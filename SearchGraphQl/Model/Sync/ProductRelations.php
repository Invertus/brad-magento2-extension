<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\Sync;

use Magento\Framework\App\ResourceConnection;

/**
 * Parents and children of bundle, grouped and configurable products.
 *
 * A child's change can change its parent: Magento's stock index sets a bundle out of stock when a
 * required part runs out, but the changelog gets a row for the part only.
 */
class ProductRelations
{
    private const TABLE = 'catalog_product_relation';
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
     * @param int[] $childIds
     * @return array<int, int[]> child id => its parent ids, only for children that have parents
     */
    public function parentsOf(array $childIds): array
    {
        return $this->read('child_id', 'parent_id', $childIds);
    }

    /**
     * @param int[] $parentIds
     * @return array<int, int[]> parent id => its child ids, only for products that have children
     */
    public function childrenOf(array $parentIds): array
    {
        return $this->read('parent_id', 'child_id', $parentIds);
    }

    /**
     * @param int $fromId
     * @param int|null $toId Null: no upper end
     * @return array<int, int[]> parent id => its child ids, for parents with an id in the range
     */
    public function childrenInRange(int $fromId, ?int $toId): array
    {
        $connection = $this->resource->getConnection();
        $select = $connection->select()
            ->from($this->resource->getTableName(self::TABLE), ['parent_id', 'child_id'])
            ->where('parent_id >= ?', $fromId);
        if ($toId !== null) {
            $select->where('parent_id <= ?', $toId);
        }

        $related = [];
        foreach ($connection->fetchAll($select) as $row) {
            $related[(int)$row['parent_id']][] = (int)$row['child_id'];
        }

        return $related;
    }

    /**
     * @param string $byColumn
     * @param string $otherColumn
     * @param int[] $ids
     * @return array<int, int[]>
     */
    private function read(string $byColumn, string $otherColumn, array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if (empty($ids)) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);
        $related = [];
        foreach (array_chunk($ids, self::BATCH_SIZE) as $batch) {
            foreach ($connection->fetchAll(
                $connection->select()
                    ->from($table, [$byColumn, $otherColumn])
                    ->where($byColumn . ' IN (?)', $batch)
            ) as $row) {
                $related[(int)$row[$byColumn]][] = (int)$row[$otherColumn];
            }
        }

        return $related;
    }
}
