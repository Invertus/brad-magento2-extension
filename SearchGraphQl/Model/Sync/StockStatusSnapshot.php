<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\Sync;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * The default stock's status per product as the sync last saw it.
 *
 * `bradProducts` reads the status from `cataloginventory_stock_status`, and some writers change it with no
 * changelog row: a stock import that writes it directly while the stock item stays the same (a bundle the
 * feed lists in stock while Magento computed it out), or the inventory modules' order consumer, which
 * writes it from reservations. Comparing this copy with Magento's table finds those changes.
 */
class StockStatusSnapshot
{
    private const TABLE = 'bradsearch_stock_status';

    private const SOURCE = 'cataloginventory_stock_status';

    private const DEFAULT_STOCK = 1;

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
     * Rows whose status differs from the copy, or that the copy does not have yet. An empty copy is filled
     * instead, and nothing is returned: every product would differ, and the stored hashes already describe
     * what BradSearch has.
     *
     * @return array<int, array{product_id: int, website_id: int, stock_status: int}>
     */
    public function changes(): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $source = $this->resource->getTableName(self::SOURCE);

        if (!$connection->fetchOne($connection->select()->from($table, ['product_id'])->limit(1))) {
            $connection->query($connection->insertFromSelect(
                $connection->select()
                    ->from($source, ['product_id', 'website_id', 'stock_status'])
                    ->where('stock_id = ?', self::DEFAULT_STOCK),
                $table,
                ['product_id', 'website_id', 'stock_status'],
                AdapterInterface::INSERT_IGNORE
            ));
            return [];
        }

        $rows = [];
        foreach ($connection->fetchAll(
            $connection->select()
                ->from(['s' => $source], ['product_id', 'website_id', 'stock_status'])
                ->joinLeft(
                    ['n' => $table],
                    'n.product_id = s.product_id AND n.website_id = s.website_id',
                    []
                )
                ->where('s.stock_id = ?', self::DEFAULT_STOCK)
                ->where('n.product_id IS NULL OR n.stock_status <> s.stock_status')
        ) as $row) {
            $rows[] = [
                'product_id' => (int)$row['product_id'],
                'website_id' => (int)$row['website_id'],
                'stock_status' => (int)$row['stock_status'],
            ];
        }

        return $rows;
    }

    /**
     * Store the rows changes() returned, with the values it read: a status written since then still
     * differs on the next run.
     *
     * @param array<int, array{product_id: int, website_id: int, stock_status: int}> $rows
     * @return void
     */
    public function save(array $rows): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        foreach (array_chunk($rows, self::BATCH_SIZE) as $batch) {
            $connection->insertOnDuplicate($table, $batch, ['stock_status']);
        }
    }

    /**
     * Products whose status differs from the copy. A product the copy does not have is not counted: the
     * sync has not seen it yet, so no stored hash relies on it.
     *
     * @param int[]|null $productIds Null for every product in the id range
     * @param int $fromId
     * @param int|null $toId Null: no end
     * @return int[]
     */
    public function changedAmong(?array $productIds, int $fromId = 0, ?int $toId = null): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->distinct(true)
            ->from(['s' => $this->resource->getTableName(self::SOURCE)], ['product_id'])
            ->joinInner(
                ['n' => $this->resource->getTableName(self::TABLE)],
                'n.product_id = s.product_id AND n.website_id = s.website_id',
                []
            )
            ->where('s.stock_id = ?', self::DEFAULT_STOCK)
            ->where('n.stock_status <> s.stock_status');

        if ($productIds === null) {
            $select->where('s.product_id >= ?', $fromId);
            if ($toId !== null) {
                $select->where('s.product_id <= ?', $toId);
            }
            return array_map('intval', $connection->fetchCol($select));
        }

        $changed = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $productIds))), self::BATCH_SIZE) as $batch) {
            $batchSelect = clone $select;
            $changed[] = $connection->fetchCol($batchSelect->where('s.product_id IN (?)', $batch));
        }

        return array_map('intval', array_merge([], ...$changed));
    }
}
