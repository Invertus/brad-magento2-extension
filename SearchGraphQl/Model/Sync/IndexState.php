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
 * `bradProducts` reads categories from the category index, catalog rule prices from the rule price
 * index and the stock status from the stock index. The sync job runs in a different cron group than the
 * index job, so a product sent before the index job runs is fetched with its old data, and nothing sends
 * it again.
 *
 * The stock status is waited for with Magento's inventory modules too: a stock save or import can write
 * the stock item (which adds the changelog row) before the status, and the stock index writes a
 * bundle's or configurable's status when it indexes a child's row.
 */
class IndexState
{
    public const CATEGORIES = 'catalog_product_category';

    public const PRICE_RULES = 'catalogrule_product';

    public const STOCK = 'cataloginventory_stock';

    /**
     * The indexers whose full reindex suspends each view: its own and those sharing its index.
     */
    private const INDEXERS = [
        self::CATEGORIES => ['catalog_category_product', 'catalog_product_category'],
        self::PRICE_RULES => ['catalogrule_rule', 'catalogrule_product'],
        self::STOCK => ['cataloginventory_stock'],
    ];

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
     * @return int[] The products whose change may still be waiting for the index job
     */
    public function notIndexed(string $viewId, array $productIds): array
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
        // A full reindex moves the version to the end when it starts, and its new tables go live only when
        // it ends. The hourly changelog cleanup may delete the rows of changes made before it started, so
        // the log cannot tell which products read old data until then: every product waits.
        // A reindex that failed leaves the view idle with that version, the old tables live and the indexer
        // invalid until it is rebuilt, so every product waits for that rebuild too.
        if ($state['status'] === 'suspended' || $this->rebuildPending($viewId)) {
            return $productIds;
        }

        // Changelog tables have no index on entity_id, so filtering by the products would scan the whole
        // backlog once per batch of ids. The backlog is read once and matched here instead.
        $pending = array_fill_keys(array_map('intval', $connection->fetchCol(
            $connection->select()
                ->distinct(true)
                ->from($changelog, ['entity_id'])
                ->where('version_id > ?', (int)$state['version_id'])
        )), true);

        return array_values(array_filter($productIds, static function (int $productId) use ($pending): bool {
            return isset($pending[$productId]);
        }));
    }

    /**
     * @param string $viewId
     * @return bool True while an indexer of the view is not valid: being rebuilt, or waiting for a rebuild
     */
    private function rebuildPending(string $viewId): bool
    {
        $connection = $this->resource->getConnection();

        return (int)$connection->fetchOne(
            $connection->select()
                ->from($this->resource->getTableName('indexer_state'), ['COUNT(*)'])
                ->where('indexer_id IN (?)', self::INDEXERS[$viewId] ?? [$viewId])
                ->where('status <> ?', 'valid')
        ) > 0;
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
