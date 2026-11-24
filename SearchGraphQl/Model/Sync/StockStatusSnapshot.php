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
     * Rows whose status differs from the copy, that the copy does not have yet, or that are gone from
     * Magento's table (stock_status null). The stock index deletes a disabled product's row and writes it
     * again once the product is enabled; a copy row kept meanwhile would match the new row and hide it,
     * while BradSearch may have read the product with no row (out of stock). An empty copy is filled
     * instead, and nothing is returned: every product would differ, and the stored hashes already describe
     * what BradSearch has.
     *
     * @return array<int, array{product_id: int, website_id: int, stock_status: int|null}>
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
        foreach ($connection->fetchAll(
            $connection->select()
                ->from(['n' => $table], ['product_id', 'website_id'])
                ->joinLeft(
                    ['s' => $source],
                    'n.product_id = s.product_id AND n.website_id = s.website_id AND s.stock_id = '
                    . self::DEFAULT_STOCK,
                    []
                )
                ->where('s.product_id IS NULL')
        ) as $row) {
            $rows[] = [
                'product_id' => (int)$row['product_id'],
                'website_id' => (int)$row['website_id'],
                'stock_status' => null,
            ];
        }

        return $rows;
    }

    /**
     * Store the rows changes() returned, with the values it read: a status written since then still
     * differs on the next run.
     *
     * @param array<int, array{product_id: int, website_id: int, stock_status: int|null}> $rows A null status
     *        removes the row
     * @return void
     */
    public function save(array $rows): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);

        $present = [];
        $gone = [];
        foreach ($rows as $row) {
            if ($row['stock_status'] === null) {
                $gone[$row['website_id']][] = $row['product_id'];
            } else {
                $present[] = $row;
            }
        }
        foreach (array_chunk($present, self::BATCH_SIZE) as $batch) {
            $connection->insertOnDuplicate($table, $batch, ['stock_status']);
        }
        foreach ($gone as $websiteId => $productIds) {
            foreach (array_chunk($productIds, self::BATCH_SIZE) as $batch) {
                $connection->delete($table, ['website_id = ?' => $websiteId, 'product_id IN (?)' => $batch]);
            }
        }
    }

    /**
     * Products whose status differs from the copy, or whose row is gone from Magento's table. A product the
     * copy does not have is not counted: the sync has not seen it yet, so no stored hash relies on it.
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
            ->from(['n' => $this->resource->getTableName(self::TABLE)], ['product_id'])
            ->joinLeft(
                ['s' => $this->resource->getTableName(self::SOURCE)],
                'n.product_id = s.product_id AND n.website_id = s.website_id AND s.stock_id = '
                . self::DEFAULT_STOCK,
                []
            )
            ->where('s.product_id IS NULL OR n.stock_status <> s.stock_status');

        if ($productIds === null) {
            $select->where('n.product_id >= ?', $fromId);
            if ($toId !== null) {
                $select->where('n.product_id <= ?', $toId);
            }
            return array_map('intval', $connection->fetchCol($select));
        }

        $changed = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $productIds))), self::BATCH_SIZE) as $batch) {
            $batchSelect = clone $select;
            $changed[] = $connection->fetchCol($batchSelect->where('n.product_id IN (?)', $batch));
        }

        return array_map('intval', array_merge([], ...$changed));
    }
}
