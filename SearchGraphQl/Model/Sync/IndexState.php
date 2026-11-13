<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\Sync;

use Magento\Framework\App\ResourceConnection;

/**
 * Tells which products have a change that one of Magento's scheduled indexes has not processed yet.
 *
 * `bradProducts` reads categories from the category index and catalog rule prices from the rule price
 * index. The sync job runs in a different cron group than the index job, so a product sent before the
 * index job runs is fetched with its old categories or old rule price, and nothing sends it again.
 */
class IndexState
{
    public const CATEGORIES = 'catalog_product_category';

    public const PRICE_RULES = 'catalogrule_product';

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
     * @param string $viewId One of the view constants
     * @param int[] $productIds
     * @param int[] $held Products already held; a full reindex keeps them held even if their rows are cleaned
     * @return int[] The products whose change is still waiting for the index job
     */
    public function notIndexed(string $viewId, array $productIds, array $held = []): array
    {
        if (empty($productIds)) {
            return [];
        }

        $connection = $this->resource->getConnection();
        $changelog = $this->resource->getTableName($viewId . '_cl');
        $state = $connection->fetchRow(
            $connection->select()
                ->from($this->resource->getTableName('mview_state'), ['mode', 'status', 'version_id'])
                ->where('view_id = ?', $viewId)
        );
        // "Update on Save" (no enabled view) writes the index during the save itself.
        if (!$state || $state['mode'] !== 'enabled' || !$connection->isTableExists($changelog)) {
            return [];
        }

        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        // A full reindex moves the version to the end when it starts, but its new tables go live only
        // when it ends. Until then every product with a change in the log may still read old data.
        $suspended = $state['status'] === 'suspended';
        $waiting = $suspended ? [array_values(array_intersect($productIds, array_map('intval', $held)))] : [];
        foreach (array_chunk($productIds, self::BATCH_SIZE) as $batch) {
            $select = $connection->select()
                ->distinct(true)
                ->from($changelog, ['entity_id'])
                ->where('entity_id IN (?)', $batch);
            if (!$suspended) {
                $select->where('version_id > ?', (int)$state['version_id']);
            }
            $waiting[] = $connection->fetchCol($select);
        }

        return array_values(array_unique(array_map('intval', array_merge([], ...$waiting))));
    }

    /**
     * Websites with an active catalog price rule. Only their store views need to wait for the rule index.
     *
     * @return int[]
     */
    public function websitesWithPriceRules(): array
    {
        $connection = $this->resource->getConnection();
        $rules = $this->resource->getTableName('catalogrule');
        $websites = $this->resource->getTableName('catalogrule_website');
        if (!$connection->isTableExists($rules) || !$connection->isTableExists($websites)) {
            return [];
        }

        // One day of slack on to_date: store time zones differ from the database's, and holding a little
        // longer is safe while sending too early is not.
        return array_map('intval', $connection->fetchCol(
            $connection->select()
                ->distinct(true)
                ->from(['w' => $websites], ['website_id'])
                ->joinInner(['r' => $rules], 'r.rule_id = w.rule_id', [])
                ->where('r.is_active = 1')
                ->where('r.to_date IS NULL OR r.to_date >= CURDATE() - INTERVAL 1 DAY')
        ));
    }
}
