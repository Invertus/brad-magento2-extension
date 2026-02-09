<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\Resolver;

use BradSearch\SearchGraphQl\Model\Api\Auth\ApiKeyValidator;
use Magento\Catalog\Model\Product\Attribute\Source\Status;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Exception\GraphQlInputException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Resolver for bradProducts query — direct MySQL product listing for BradSearch sync.
 *
 * Bypasses ElasticSearch entirely. No stock filters applied.
 * Requires valid X-BradSearch-Api-Key header.
 */
class BradProducts implements ResolverInterface
{
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
     * @param ApiKeyValidator $apiKeyValidator
     * @param CollectionFactory $collectionFactory
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        ApiKeyValidator $apiKeyValidator,
        CollectionFactory $collectionFactory,
        StoreManagerInterface $storeManager
    ) {
        $this->apiKeyValidator = $apiKeyValidator;
        $this->collectionFactory = $collectionFactory;
        $this->storeManager = $storeManager;
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
        $collection->addAttributeToSelect('*');
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

        $totalCount = $collection->getSize();

        $collection->setPageSize($pageSize);
        $collection->setCurPage($currentPage + 1);

        $items = [];
        foreach ($collection as $product) {
            $productData = $product->getData();
            $productData['model'] = $product;
            $items[] = $productData;
        }

        $totalPages = $pageSize > 0 ? (int)ceil($totalCount / $pageSize) : 0;

        return [
            'total_count' => $totalCount,
            'items' => $items,
            'page_info' => [
                'page_size' => $pageSize,
                'current_page' => $currentPage,
                'total_pages' => $totalPages,
            ],
        ];
    }
}
