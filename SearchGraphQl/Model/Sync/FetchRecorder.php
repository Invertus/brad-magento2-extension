<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\Sync;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Marks the products BradSearch fetched while they had a change the sync had not handled yet.
 *
 * The store's sync cursor is read before any product data, and the changelog after the whole request
 * has read its data. Any change BradSearch may have read in a state the stored hash does not describe
 * is then in the changelog after that cursor, or belongs to a product held for Magento's index (the
 * cursor moved past its changes, but no hash was stored for them), so the product is marked to be sent
 * again. A product already sent at the hold limit is not marked: it has a hash, any new change sends it
 * again, and it is checked again once indexed; marking it would send it on every fetch while it waits.
 * Both changelogs the sync reads count (search and stock), and a fetched bundle, grouped or configurable
 * product counts as changed when one of its children has a row: its stock status follows the child's. So
 * does a fetched product whose stock status differs from the status the sync last saw: some writers
 * change it with no changelog row.
 *
 * A product the request could not build did not reach BradSearch, so its stored hash is forgotten and
 * its next changelog entry sends it again. It is not marked: a product that keeps failing would then
 * be sent on every run.
 */
class FetchRecorder
{
    private const SYNC_STATE_TABLE = 'bradsearch_sync_state';

    /**
     * @var array<int, array{0: int, 1: array{0: int, 1: int|null}, 2: int[]|null, 3: int, 4: int|null}> store id,
     *      cursors, product ids (null for an id range), first id, last id (null: no end)
     */
    private array $fetches = [];

    /**
     * @var array<int, int[]> store id => products the request could not build
     */
    private array $failures = [];

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resource;

    /**
     * @var ChangelogReader
     */
    private ChangelogReader $changelogReader;

    /**
     * @var ResendProducts
     */
    private ResendProducts $resendProducts;

    /**
     * @var HeldProducts
     */
    private HeldProducts $heldProducts;

    /**
     * @var bool|null
     */
    private ?bool $holding = null;

    /**
     * @var ChangeFilter
     */
    private ChangeFilter $changeFilter;

    /**
     * @var WebhookNotifier
     */
    private WebhookNotifier $webhookNotifier;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var ChangelogReader
     */
    private ChangelogReader $stockChangelogReader;

    /**
     * @var ProductRelations
     */
    private ProductRelations $productRelations;

    /**
     * @var StockStatusSnapshot
     */
    private StockStatusSnapshot $stockStatusSnapshot;

    /**
     * @var bool|null
     */
    private ?bool $snapshot = null;

    /**
     * @param ResourceConnection $resource
     * @param ChangelogReader $changelogReader
     * @param ResendProducts $resendProducts
     * @param HeldProducts $heldProducts
     * @param ChangeFilter $changeFilter
     * @param WebhookNotifier $webhookNotifier
     * @param LoggerInterface $logger
     * @param ChangelogReader $stockChangelogReader Reads cataloginventory_stock_cl
     * @param ProductRelations $productRelations
     * @param StockStatusSnapshot $stockStatusSnapshot
     */
    public function __construct(
        ResourceConnection $resource,
        ChangelogReader $changelogReader,
        ResendProducts $resendProducts,
        HeldProducts $heldProducts,
        ChangeFilter $changeFilter,
        WebhookNotifier $webhookNotifier,
        LoggerInterface $logger,
        ChangelogReader $stockChangelogReader,
        ProductRelations $productRelations,
        StockStatusSnapshot $stockStatusSnapshot
    ) {
        $this->resource = $resource;
        $this->changelogReader = $changelogReader;
        $this->resendProducts = $resendProducts;
        $this->heldProducts = $heldProducts;
        $this->changeFilter = $changeFilter;
        $this->webhookNotifier = $webhookNotifier;
        $this->logger = $logger;
        $this->stockChangelogReader = $stockChangelogReader;
        $this->productRelations = $productRelations;
        $this->stockStatusSnapshot = $stockStatusSnapshot;
    }

    /**
     * Read before any product data of the request
     *
     * @param int $storeId
     * @return array{0: int, 1: int|null}|null The store's search and stock changelog cursors (null for a
     *         stock changelog the sync does not read yet); null when the store has no live sync to protect
     */
    public function cursor(int $storeId): ?array
    {
        try {
            if (!$this->webhookNotifier->isSyncEnabled($storeId) || !$this->resendProducts->isAvailable()) {
                return null;
            }

            $connection = $this->resource->getConnection();
            $table = $this->resource->getTableName(self::SYNC_STATE_TABLE);
            $columns = ['last_version_id'];
            if ($connection->tableColumnExists($table, 'last_stock_version_id')) {
                $columns[] = 'last_stock_version_id';
            }
            $row = $connection->fetchRow(
                $connection->select()->from($table, $columns)->where('store_id = ?', $storeId)
            );
            if (!$row || $row['last_version_id'] === null) {
                return null;
            }

            $stockCursor = $row['last_stock_version_id'] ?? null;

            return [(int)$row['last_version_id'], $stockCursor === null ? null : (int)$stockCursor];
        } catch (\Throwable $e) {
            $this->logger->error('BradSearch Sync: Could not read the sync cursor for a fetch', [
                'store_id' => $storeId,
                'error' => $e->getMessage()
            ]);
            return null;
        }
    }

    /**
     * A fetch of named products. BradSearch reads a named product it does not get back as removed, so the
     * named ones count whether or not they were returned.
     *
     * @param int $storeId
     * @param array{0: int, 1: int|null}|null $cursor From cursor(), read before the products were loaded
     * @param int[] $productIds
     * @return void
     */
    public function record(int $storeId, ?array $cursor, array $productIds): void
    {
        if ($cursor === null || empty($productIds)) {
            return;
        }

        $this->fetches[] = [$storeId, $cursor, array_values(array_unique(array_map('intval', $productIds))), 0, null];
    }

    /**
     * A page of the catalog. A product missing from it is missing from BradSearch's copy, so every id the
     * page stands for counts: from the previous page's last product to this page's last one.
     *
     * @param int $storeId
     * @param array{0: int, 1: int|null}|null $cursor From cursor(), read before the products were loaded
     * @param int $fromId
     * @param int|null $toId Null for the last page
     * @return void
     */
    public function recordRange(int $storeId, ?array $cursor, int $fromId, ?int $toId): void
    {
        if ($cursor === null) {
            return;
        }

        $this->fetches[] = [$storeId, $cursor, null, $fromId, $toId];
    }

    /**
     * A product left out of the response because it could not be built
     *
     * @param int $storeId
     * @param int $productId
     * @return void
     */
    public function recordFailure(int $storeId, int $productId): void
    {
        $this->failures[$storeId][] = $productId;
    }

    /**
     * Run once the request has read everything it returns
     *
     * @return void
     */
    public function flush(): void
    {
        $fetches = $this->fetches;
        $failures = $this->failures;
        $this->fetches = [];
        $this->failures = [];

        foreach ($failures as $storeId => $productIds) {
            try {
                $this->changeFilter->forget($productIds, $storeId);
                $this->logger->info('BradSearch Sync: Fetched products could not be built, their next change is sent', [
                    'store_id' => $storeId,
                    'product_count' => count(array_unique($productIds))
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('BradSearch Sync: Could not forget the hashes of products that could not be built', [
                    'store_id' => $storeId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        foreach ($fetches as [$storeId, $cursor, $productIds, $fromId, $toId]) {
            try {
                $changed = array_merge(
                    $this->changedAfter($cursor, $productIds, $fromId, $toId),
                    $this->parentsWithChangedChildren($cursor, $productIds, $fromId, $toId)
                );
                if ($this->holdingAvailable()) {
                    $changed = array_values(array_unique(array_merge(
                        $changed,
                        $this->heldProducts->notSentAmong($storeId, $productIds, $fromId, $toId)
                    )));
                }
                if (empty($changed)) {
                    continue;
                }

                $this->resendProducts->mark($storeId, $changed);
                $this->logger->info('BradSearch Sync: Fetched products had a change not sent yet, sending them again', [
                    'store_id' => $storeId,
                    'product_count' => count($changed)
                ]);
            } catch (\Throwable $e) {
                $this->logger->error('BradSearch Sync: Could not mark fetched products to send again', [
                    'store_id' => $storeId,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * @param array{0: int, 1: int|null} $cursor
     * @param int[]|null $productIds Null for the id range
     * @param int $fromId
     * @param int|null $toId
     * @return int[] Products with a row after the cursor in the search or the stock changelog, or a stock
     *         status the sync has not seen
     */
    private function changedAfter(array $cursor, ?array $productIds, int $fromId, ?int $toId): array
    {
        [$searchCursor, $stockCursor] = $cursor;
        $changed = $productIds === null
            ? $this->changelogReader->getProductsChangedAfterInRange($searchCursor, $fromId, $toId)
            : $this->changelogReader->getProductsChangedAfter($searchCursor, $productIds);
        if ($stockCursor !== null) {
            $changed = array_merge($changed, $productIds === null
                ? $this->stockChangelogReader->getProductsChangedAfterInRange($stockCursor, $fromId, $toId)
                : $this->stockChangelogReader->getProductsChangedAfter($stockCursor, $productIds));
        }
        if ($this->snapshotAvailable()) {
            $changed = array_merge($changed, $this->stockStatusSnapshot->changedAmong($productIds, $fromId, $toId));
        }

        return $changed;
    }

    /**
     * A child's change can change its parent's data (a bundle's stock status) with no row for the parent.
     *
     * @param array{0: int, 1: int|null} $cursor
     * @param int[]|null $productIds Null for the id range
     * @param int $fromId
     * @param int|null $toId
     * @return int[] Fetched parents with a child changed after the cursor
     */
    private function parentsWithChangedChildren(array $cursor, ?array $productIds, int $fromId, ?int $toId): array
    {
        $children = $productIds === null
            ? $this->productRelations->childrenInRange($fromId, $toId)
            : $this->productRelations->childrenOf($productIds);
        if (empty($children)) {
            return [];
        }

        $changed = array_fill_keys($this->changedAfter(
            $cursor,
            array_values(array_unique(array_merge([], ...array_values($children)))),
            0,
            null
        ), true);
        $parents = [];
        foreach ($children as $parentId => $childIds) {
            foreach ($childIds as $childId) {
                if (isset($changed[$childId])) {
                    $parents[] = $parentId;
                    break;
                }
            }
        }

        return $parents;
    }

    /**
     * @return bool False until setup:upgrade has created the stock status table
     */
    private function snapshotAvailable(): bool
    {
        if ($this->snapshot === null) {
            $this->snapshot = $this->stockStatusSnapshot->isAvailable();
        }

        return $this->snapshot;
    }

    /**
     * @return bool False until setup:upgrade has created the held products table
     */
    private function holdingAvailable(): bool
    {
        if ($this->holding === null) {
            $this->holding = $this->heldProducts->isAvailable();
        }

        return $this->holding;
    }
}
