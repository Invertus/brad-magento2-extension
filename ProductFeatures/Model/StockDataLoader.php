<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\ProductFeatures\Model;

use Magento\Framework\App\ResourceConnection;

/**
 * Batch loader for stock data to avoid N+1 queries
 *
 * Usage pattern:
 * 1. Resolvers call addToQueue() to register product IDs
 * 2. Resolvers return a deferred value (ValueFactory callback)
 * 3. When callback is invoked, getStockData() batch-loads all queued IDs
 * 4. Subsequent calls use cached data
 */
class StockDataLoader
{
    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resource;

    /**
     * @var array<int, bool> Product IDs waiting to be loaded
     */
    private array $queue = [];

    /**
     * @var array<int, array> Loaded stock data indexed by product ID
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
     * Add product ID to the batch queue
     *
     * @param int $productId
     * @return void
     */
    public function addToQueue(int $productId): void
    {
        if (!isset($this->loaded[$productId])) {
            $this->queue[$productId] = true;
        }
    }

    /**
     * Get stock data for a product (triggers batch load if needed)
     *
     * @param int $productId
     * @return array{is_in_stock: bool, backorders: int, qty: float}
     */
    public function getStockData(int $productId): array
    {
        $this->loadPendingQueue();

        return $this->loaded[$productId] ?? [
            'is_in_stock' => false,
            'backorders' => 0,
            'qty' => 0.0
        ];
    }

    /**
     * Get is_in_stock value for a product
     *
     * @param int $productId
     * @return bool
     */
    public function getIsInStock(int $productId): bool
    {
        return (bool)($this->getStockData($productId)['is_in_stock'] ?? false);
    }

    /**
     * Get allows_backorders value for a product
     *
     * @param int $productId
     * @return bool
     */
    public function getAllowsBackorders(int $productId): bool
    {
        return (int)($this->getStockData($productId)['backorders'] ?? 0) > 0;
    }

    /**
     * Load all queued product IDs in one batch query
     *
     * @return void
     */
    private function loadPendingQueue(): void
    {
        if (empty($this->queue)) {
            return;
        }

        $productIds = array_keys($this->queue);
        $this->queue = [];

        $connection = $this->resource->getConnection();
        $stockItemTable = $connection->getTableName('cataloginventory_stock_item');
        $stockStatusTable = $connection->getTableName('cataloginventory_stock_status');

        $select = $connection->select()
            ->from(
                ['item' => $stockItemTable],
                ['product_id', 'backorders', 'qty']
            )
            ->joinLeft(
                ['status' => $stockStatusTable],
                'item.product_id = status.product_id AND status.stock_id = 1',
                ['stock_status']
            )
            ->where('item.product_id IN (?)', $productIds);

        $results = $connection->fetchAll($select);

        foreach ($results as $row) {
            $this->loaded[(int)$row['product_id']] = [
                'is_in_stock' => (bool)($row['stock_status'] ?? 0),
                'backorders' => (int)$row['backorders'],
                'qty' => (float)$row['qty']
            ];
        }

        // Mark products without stock records as out of stock
        foreach ($productIds as $productId) {
            if (!isset($this->loaded[$productId])) {
                $this->loaded[$productId] = [
                    'is_in_stock' => false,
                    'backorders' => 0,
                    'qty' => 0.0
                ];
            }
        }
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
