<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\Sync;

use Magento\Framework\App\ResourceConnection;

/**
 * Products a store has read from the changelog but Magento has not indexed yet. The store's changelog
 * cursor moves past them, so they are kept here until Magento has indexed them, also after they were sent
 * at the hold limit.
 */
class HeldProducts
{
    private const TABLE = 'bradsearch_held_product';

    private const BATCH_SIZE = 1000;

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resource;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * False until setup:upgrade has created the table
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return $this->resource->getConnection()->isTableExists($this->resource->getTableName(self::TABLE));
    }

    /**
     * @param int $storeId
     * @return array<int, bool> product id => already sent at the hold limit
     */
    public function forStore(int $storeId): array
    {
        $connection = $this->resource->getConnection();
        $held = [];
        foreach ($connection->fetchPairs(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['product_id', 'sent_at_limit'])
                ->where('store_id = ?', $storeId)
        ) as $productId => $sentAtLimit) {
            $held[(int)$productId] = (bool)$sentAtLimit;
        }

        return $held;
    }

    /**
     * Release the products Magento has indexed and hold the ones still waiting. A product held again keeps
     * the time it was first held and whether it was sent at the limit.
     *
     * @param int $storeId
     * @param int[] $release
     * @param int[] $hold
     * @param int[] $sentAtLimit Held products just sent at the hold limit
     * @return void
     */
    public function update(int $storeId, array $release, array $hold, array $sentAtLimit = []): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);

        foreach (array_chunk($release, self::BATCH_SIZE) as $batch) {
            $connection->delete($table, ['store_id = ?' => $storeId, 'product_id IN (?)' => $batch]);
        }
        foreach (array_chunk($hold, self::BATCH_SIZE) as $batch) {
            $rows = [];
            foreach ($batch as $productId) {
                $rows[] = ['store_id' => $storeId, 'product_id' => (int)$productId];
            }
            $connection->insertOnDuplicate($table, $rows, ['store_id']);
        }
        foreach (array_chunk($sentAtLimit, self::BATCH_SIZE) as $batch) {
            $connection->update(
                $table,
                ['sent_at_limit' => 1],
                ['store_id = ?' => $storeId, 'product_id IN (?)' => $batch]
            );
        }
    }

    /**
     * Held products of a store that were not sent at the hold limit: no stored hash describes their changes.
     *
     * @param int $storeId
     * @param int[]|null $productIds Null for every product in the id range
     * @param int $fromId
     * @param int|null $toId Null: no end
     * @return int[]
     */
    public function notSentAmong(int $storeId, ?array $productIds, int $fromId = 0, ?int $toId = null): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE), ['product_id'])
            ->where('store_id = ?', $storeId)
            ->where('sent_at_limit = ?', 0);

        if ($productIds === null) {
            $select->where('product_id >= ?', $fromId);
            if ($toId !== null) {
                $select->where('product_id <= ?', $toId);
            }
            return array_map('intval', $connection->fetchCol($select));
        }

        $held = [];
        foreach (array_chunk($productIds, self::BATCH_SIZE) as $batch) {
            $batchSelect = clone $select;
            $held[] = $connection->fetchCol($batchSelect->where('product_id IN (?)', $batch));
        }

        return array_map('intval', array_merge([], ...$held));
    }

    /**
     * @param int $storeId
     * @param int $minutes
     * @return int[] The products this store has held longer than $minutes
     */
    public function heldLongerThan(int $storeId, int $minutes): array
    {
        $connection = $this->resource->getConnection();

        return array_map('intval', $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['product_id'])
                ->where('store_id = ?', $storeId)
                ->where('held_since < NOW() - INTERVAL ? MINUTE', $minutes)
        ));
    }
}
