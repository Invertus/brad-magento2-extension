<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\ProductFeatures\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Batch loader for URL rewrites to avoid N+1 queries
 *
 * Usage pattern (same as StockDataLoader):
 * 1. Resolvers call addToQueue() to register product IDs
 * 2. Resolvers return a deferred value (ValueFactory callback)
 * 3. When callback is invoked, getRewrite() batch-loads all queued IDs
 * 4. Subsequent calls use cached data
 */
class UrlRewriteDataLoader
{
    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resource;

    /**
     * @var array<string, bool> Queued product+store keys waiting to be loaded
     */
    private array $queue = [];

    /**
     * @var array<string, string|null> Loaded URL rewrites indexed by "productId_storeId"
     */
    private array $loaded = [];

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * Add product ID to the batch queue for a given store
     *
     * @param int $productId
     * @param int $storeId
     * @return void
     */
    public function addToQueue(int $productId, int $storeId): void
    {
        $key = $productId . '_' . $storeId;
        if (!isset($this->loaded[$key])) {
            $this->queue[$key] = ['product_id' => $productId, 'store_id' => $storeId];
        }
    }

    /**
     * Get URL rewrite request_path for a product (triggers batch load if needed)
     *
     * @param int $productId
     * @param int $storeId
     * @return string|null The shortest request_path, or null if no rewrite found
     */
    public function getRewrite(int $productId, int $storeId): ?string
    {
        $this->loadPendingQueue();

        $key = $productId . '_' . $storeId;
        return $this->loaded[$key] ?? null;
    }

    /**
     * Load all queued product URL rewrites in one batch query
     *
     * @return void
     */
    private function loadPendingQueue(): void
    {
        if (empty($this->queue)) {
            return;
        }

        // Group by store_id for efficient querying
        $byStore = [];
        foreach ($this->queue as $entry) {
            $byStore[$entry['store_id']][] = $entry['product_id'];
        }

        $connection = $this->resource->getConnection();
        $urlRewriteTable = $connection->getTableName('url_rewrite');

        foreach ($byStore as $storeId => $productIds) {
            $select = $connection->select()
                ->from(
                    $urlRewriteTable,
                    ['entity_id', 'request_path']
                )
                ->where('entity_type = ?', 'product')
                ->where('entity_id IN (?)', $productIds)
                ->where('store_id = ?', $storeId)
                ->where('redirect_type = ?', 0);

            $results = $connection->fetchAll($select);

            // Group by entity_id, pick shortest request_path per product
            $pathsByProduct = [];
            foreach ($results as $row) {
                $pid = (int)$row['entity_id'];
                $path = $row['request_path'];
                if (!isset($pathsByProduct[$pid]) || strlen($path) < strlen($pathsByProduct[$pid])) {
                    $pathsByProduct[$pid] = $path;
                }
            }

            // Store results
            foreach ($productIds as $productId) {
                $key = $productId . '_' . $storeId;
                $this->loaded[$key] = $pathsByProduct[$productId] ?? null;
            }
        }

        $this->queue = [];
    }

    /**
     * Clear cache (useful for testing or long-running processes)
     *
     * @return void
     */
    public function clearCache(): void
    {
        $this->queue = [];
        $this->loaded = [];
    }
}
