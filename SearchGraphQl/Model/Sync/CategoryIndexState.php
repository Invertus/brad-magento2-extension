<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\Sync;

use Magento\Framework\App\ResourceConnection;

/**
 * Tells which products have a category change that Magento's scheduled category index has not processed yet.
 *
 * `bradProducts` reads categories from that index, and the sync job runs in a different cron group than
 * the index job. A product sent before the index job runs is fetched with its old categories, and nothing
 * sends it again.
 */
class CategoryIndexState
{
    private const VIEW_ID = 'catalog_product_category';

    private const CHANGELOG_TABLE = 'catalog_product_category_cl';

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
     * @param int[] $productIds
     * @return int[] The products whose category change is still waiting for the index job
     */
    public function notIndexed(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $stateTable = $this->resource->getTableName('mview_state');
        $changelog = $this->resource->getTableName(self::CHANGELOG_TABLE);

        $state = $connection->fetchRow(
            $connection->select()
                ->from($stateTable, ['mode', 'version_id'])
                ->where('view_id = ?', self::VIEW_ID)
        );
        // "Update on Save" (no enabled view) writes the index during the save itself.
        if (!$state || $state['mode'] !== 'enabled' || !$connection->isTableExists($changelog)) {
            return [];
        }

        $waiting = [];
        foreach (array_chunk(array_values(array_unique(array_map('intval', $productIds))), self::BATCH_SIZE) as $batch) {
            $waiting[] = $connection->fetchCol(
                $connection->select()
                    ->distinct(true)
                    ->from($changelog, ['entity_id'])
                    ->where('version_id > ?', (int)$state['version_id'])
                    ->where('entity_id IN (?)', $batch)
            );
        }

        return array_map('intval', array_merge([], ...$waiting));
    }
}
