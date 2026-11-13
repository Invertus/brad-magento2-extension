<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\Sync;

use Magento\Framework\App\ResourceConnection;

/**
 * Products a store has read from the changelog but not sent yet, because their category change
 * is not indexed. The store's changelog cursor moves past them, so they are kept here until sent.
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
     * @return int[]
     */
    public function forStore(int $storeId): array
    {
        $connection = $this->resource->getConnection();

        return array_map('intval', $connection->fetchCol(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['product_id'])
                ->where('store_id = ?', $storeId)
        ));
    }

    /**
     * Release the products that were sent and hold the ones still waiting. A product held again keeps
     * the time it was first held.
     *
     * @param int $storeId
     * @param int[] $release
     * @param int[] $hold
     * @return void
     */
    public function update(int $storeId, array $release, array $hold): void
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
    }

    /**
     * @param int $storeId
     * @param int $minutes
     * @return array{count: int, held_since: string|null, sample: int[]} The products held longer than $minutes
     */
    public function heldLongerThan(int $storeId, int $minutes): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $where = static function ($select) use ($storeId, $minutes) {
            return $select
                ->where('store_id = ?', $storeId)
                ->where('held_since < NOW() - INTERVAL ? MINUTE', $minutes);
        };

        $summary = $connection->fetchRow(
            $where($connection->select()->from($table, ['count' => 'COUNT(*)', 'held_since' => 'MIN(held_since)']))
        );
        $sample = $connection->fetchCol(
            $where($connection->select()->from($table, ['product_id']))->order('held_since')->limit(20)
        );

        return [
            'count' => (int)($summary['count'] ?? 0),
            'held_since' => $summary['held_since'] ?? null,
            'sample' => array_map('intval', $sample),
        ];
    }
}
