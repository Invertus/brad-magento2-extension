<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\ProductFeatures\Model;

use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Batch loader for product data with optimized attribute loading
 *
 * This loader solves the N+1 problem when fetching custom attributes:
 * Instead of calling ProductRepository::getById() for each product,
 * we collect all product IDs and load them in a single collection query.
 *
 * OPTIMIZATION: Only loads searchable/filterable attributes + whitelist,
 * instead of ALL attributes ('*') which is very expensive.
 */
class ProductDataLoader
{
    /**
     * Whitelist attributes to always load (same as Features resolver)
     */
    private const WHITELIST_ATTRIBUTES = [
        'manufacturer',
        'mpn',
        'barcode',
        'middle_of_product_name',
        'beginning_of_product_nam',
        'amazon_asin'
    ];

    /**
     * @var CollectionFactory
     */
    private CollectionFactory $collectionFactory;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resourceConnection;

    /**
     * @var array<int, ProductInterface> Product IDs queued for loading
     */
    private array $queue = [];

    /**
     * @var array<string, ProductInterface> Loaded products indexed by "productId_storeId"
     */
    private array $loaded = [];

    /**
     * @var array|null Cached list of attribute codes to load
     */
    private ?array $attributesToLoad = null;

    /**
     * @param CollectionFactory $collectionFactory
     * @param StoreManagerInterface $storeManager
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        CollectionFactory $collectionFactory,
        StoreManagerInterface $storeManager,
        ResourceConnection $resourceConnection
    ) {
        $this->collectionFactory = $collectionFactory;
        $this->storeManager = $storeManager;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * Add product to batch queue
     *
     * @param int $productId
     * @param int $storeId
     * @return void
     */
    public function addToQueue(int $productId, int $storeId): void
    {
        $key = $this->getCacheKey($productId, $storeId);
        if (!isset($this->loaded[$key])) {
            $this->queue[$key] = [
                'product_id' => $productId,
                'store_id' => $storeId
            ];
        }
    }

    /**
     * Get fully loaded product with required attributes
     *
     * @param int $productId
     * @param int $storeId
     * @return ProductInterface|null
     */
    public function getProduct(int $productId, int $storeId): ?ProductInterface
    {
        $this->loadPendingQueue();

        $key = $this->getCacheKey($productId, $storeId);
        return $this->loaded[$key] ?? null;
    }

    /**
     * Load all queued products in a single collection query
     *
     * @return void
     */
    private function loadPendingQueue(): void
    {
        if (empty($this->queue)) {
            return;
        }

        // Group products by store ID for efficient loading
        $productsByStore = [];
        foreach ($this->queue as $item) {
            $storeId = $item['store_id'];
            $productsByStore[$storeId][] = $item['product_id'];
        }

        $this->queue = [];

        // Load products per store
        foreach ($productsByStore as $storeId => $productIds) {
            $this->loadProductsForStore($productIds, $storeId);
        }
    }

    /**
     * Load products for a specific store
     *
     * @param array $productIds
     * @param int $storeId
     * @return void
     */
    private function loadProductsForStore(array $productIds, int $storeId): void
    {
        $collection = $this->collectionFactory->create();

        // Set store context
        $collection->setStoreId($storeId);

        // Filter by product IDs
        $collection->addIdFilter($productIds);

        // OPTIMIZATION: Load only required attributes instead of '*'
        $attributesToLoad = $this->getAttributesToLoad();
        foreach ($attributesToLoad as $attributeCode) {
            $collection->addAttributeToSelect($attributeCode);
        }

        // Load all products
        $collection->load();

        // Cache loaded products
        foreach ($collection as $product) {
            $key = $this->getCacheKey((int)$product->getId(), $storeId);
            $this->loaded[$key] = $product;
        }

        // Mark missing products as null to avoid re-querying
        foreach ($productIds as $productId) {
            $key = $this->getCacheKey($productId, $storeId);
            if (!isset($this->loaded[$key])) {
                $this->loaded[$key] = null;
            }
        }
    }

    /**
     * Get list of attribute codes to load
     *
     * Only loads:
     * - Searchable attributes (is_searchable = 1)
     * - Filterable attributes (is_filterable > 0)
     * - Whitelist attributes
     *
     * @return array
     */
    private function getAttributesToLoad(): array
    {
        if ($this->attributesToLoad !== null) {
            return $this->attributesToLoad;
        }

        $connection = $this->resourceConnection->getConnection();

        // Get searchable and filterable attribute codes
        $select = $connection->select()
            ->from(
                ['ea' => $connection->getTableName('eav_attribute')],
                ['attribute_code']
            )
            ->joinInner(
                ['cea' => $connection->getTableName('catalog_eav_attribute')],
                'ea.attribute_id = cea.attribute_id',
                []
            )
            ->where('ea.entity_type_id = ?', 4) // catalog_product
            ->where('cea.is_searchable = 1 OR cea.is_filterable > 0');

        $searchableFilterable = $connection->fetchCol($select);

        // Merge with whitelist and remove duplicates
        $this->attributesToLoad = array_unique(
            array_merge($searchableFilterable, self::WHITELIST_ATTRIBUTES)
        );

        return $this->attributesToLoad;
    }

    /**
     * Generate cache key for product
     *
     * @param int $productId
     * @param int $storeId
     * @return string
     */
    private function getCacheKey(int $productId, int $storeId): string
    {
        return $productId . '_' . $storeId;
    }

    /**
     * Clear cache (useful for testing)
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->queue = [];
        $this->loaded = [];
    }
}
