<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\Resolver;

use BradSearch\SearchGraphQl\Api\PriceCalculatorInterface;
use BradSearch\SearchGraphQl\Model\Api\Auth\ApiKeyValidator;
use BradSearch\SearchGraphQl\Model\Price\CalculatedPriceMapper;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use Throwable;

/**
 * Resolver for bradProducts query — direct MySQL product listing for BradSearch sync.
 *
 * Bypasses ElasticSearch entirely. Applies no stock filter of its own.
 * Requires valid X-BradSearch-Api-Key header.
 */
class BradProducts implements ResolverInterface
{
    /**
     * Attributes needed by Load 1 (BradProducts collection).
     * Load 2 (ProductDataLoader) handles searchable/filterable attributes separately.
     */
    private const COLLECTION_ATTRIBUTES = [
        'name',
        'sku',
        'url_key',
        'image',
        'short_description',
        'description',
        'price',
        'price_type',
        'special_price',
        'special_from_date',
        'special_to_date',
        'tax_class_id',
        'mm_popularity',
    ];

    /**
     * @var ApiKeyValidator
     */
    private ApiKeyValidator $apiKeyValidator;

    /**
     * @var CollectionFactory
     */
    private CollectionFactory $collectionFactory;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var PriceCalculatorInterface
     */
    private PriceCalculatorInterface $priceCalculator;

    /**
     * @var CalculatedPriceMapper
     */
    private CalculatedPriceMapper $priceMapper;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param ApiKeyValidator $apiKeyValidator
     * @param CollectionFactory $collectionFactory
     * @param StoreManagerInterface $storeManager
     * @param PriceCalculatorInterface $priceCalculator
     * @param CalculatedPriceMapper $priceMapper
     * @param LoggerInterface $logger
     */
    public function __construct(
        ApiKeyValidator $apiKeyValidator,
        CollectionFactory $collectionFactory,
        StoreManagerInterface $storeManager,
        PriceCalculatorInterface $priceCalculator,
        CalculatedPriceMapper $priceMapper,
        LoggerInterface $logger
    ) {
        $this->apiKeyValidator = $apiKeyValidator;
        $this->collectionFactory = $collectionFactory;
        $this->storeManager = $storeManager;
        $this->priceCalculator = $priceCalculator;
        $this->priceMapper = $priceMapper;
        $this->logger = $logger;
    }

    /**
     * @inheritdoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        $storeId = (int)$this->storeManager->getStore()->getId();

        if (!$this->apiKeyValidator->isValidRequest($storeId)) {
            throw new GraphQlAuthorizationException(__('Invalid or missing BradSearch API key.'));
        }

        $pageSize = (int)($args['pageSize'] ?? 20);
        $currentPage = (int)($args['currentPage'] ?? 0);
        $filters = $args['filter'] ?? [];

        if ($pageSize < 1 || $pageSize > 300) {
            throw new GraphQlInputException(__('pageSize must be between 1 and 300.'));
        }
        if ($currentPage < 0) {
            throw new GraphQlInputException(__('currentPage must be >= 0.'));
        }

        $collection = $this->collectionFactory->create();

        $collection->addAttributeToSelect(self::COLLECTION_ATTRIBUTES);
        $collection->setStoreId($storeId);
        $collection->addStoreFilter($storeId);

        // Filter out disabled products
        $collection->addAttributeToFilter('status', Status::STATUS_ENABLED);

        // Filter out "Not Visible Individually" products
        $collection->addAttributeToFilter('visibility', ['neq' => Visibility::VISIBILITY_NOT_VISIBLE]);

        // Apply filters
        if (!empty($filters['entity_id']['eq'])) {
            $collection->addFieldToFilter('entity_id', ['eq' => (int)$filters['entity_id']['eq']]);
        }
        if (!empty($filters['entity_id']['in'])) {
            $entityIds = array_map('intval', $filters['entity_id']['in']);
            $collection->addFieldToFilter('entity_id', ['in' => $entityIds]);
        }

        // The sync fetches pages over hours and treats a product it never saw as removed from the
        // shop. Without an ORDER BY, MySQL returns offset pages in plan order, so a product can move
        // between pages mid-run and never be seen. entity_id is immutable, so its order is stable.
        $collection->addAttributeToSort('entity_id', Collection::SORT_ORDER_ASC);

        $collection->setPageSize($pageSize);
        $collection->setCurPage($currentPage + 1);

        $items = [];
        $skipped = 0;
        foreach ($collection as $product) {
            try {
                $items[] = $this->buildItem($product, $storeId);
            } catch (Throwable $e) {
                // One product that cannot be priced must not void the other 299 on the page. The
                // sync reads skipped_count, so the product is accounted for rather than read as a
                // hole in the enumeration.
                $skipped++;
                $this->logger->error('bradProducts: product left out of the page because it could not be built', [
                    'entity_id' => $product->getId(),
                    'store_id' => $storeId,
                    'error' => $e->getMessage(),
                    'exception' => get_class($e),
                ]);
            }
        }

        // Read after the page has loaded. Plugins that filter the collection on load (stock, third
        // party) have applied by then, so the count matches what the pages deliver. Counted before
        // load it overstated the catalog, and the sync fetched empty pages past the real end.
        $totalCount = $collection->getSize();

        $totalPages = $pageSize > 0 ? (int)ceil($totalCount / $pageSize) : 0;

        return [
            'total_count' => $totalCount,
            'items' => $items,
            'skipped_count' => $skipped,
            'page_info' => [
                'page_size' => $pageSize,
                'current_page' => $currentPage,
                'total_pages' => $totalPages,
            ],
        ];
    }

    /**
     * @param Product $product
     * @param int $storeId
     * @return array
     */
    private function buildItem(Product $product, int $storeId): array
    {
        $productData = $product->getData();
        $productData['model'] = $product;

        $calculated = $this->priceCalculator->calculate($product, $storeId);
        $productData['calculated_price'] = $calculated !== null
            ? $this->priceMapper->toGraphQlArray($calculated)
            : null;

        return $productData;
    }
}
