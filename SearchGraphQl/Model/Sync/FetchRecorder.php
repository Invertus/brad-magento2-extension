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
 * is then in the changelog after that cursor, so the product is marked to be sent again.
 */
class FetchRecorder
{
    private const SYNC_STATE_TABLE = 'bradsearch_sync_state';

    /**
     * @var array<int, array{0: int, 1: int, 2: int[]|null, 3: int, 4: int|null}> store id, cursor, product ids
     *      (null for an id range), first id, last id (null: no end)
     */
    private array $fetches = [];

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
     * @var WebhookNotifier
     */
    private WebhookNotifier $webhookNotifier;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param ResourceConnection $resource
     * @param ChangelogReader $changelogReader
     * @param ResendProducts $resendProducts
     * @param WebhookNotifier $webhookNotifier
     * @param LoggerInterface $logger
     */
    public function __construct(
        ResourceConnection $resource,
        ChangelogReader $changelogReader,
        ResendProducts $resendProducts,
        WebhookNotifier $webhookNotifier,
        LoggerInterface $logger
    ) {
        $this->resource = $resource;
        $this->changelogReader = $changelogReader;
        $this->resendProducts = $resendProducts;
        $this->webhookNotifier = $webhookNotifier;
        $this->logger = $logger;
    }

    /**
     * Read before any product data of the request
     *
     * @param int $storeId
     * @return int|null The store's sync cursor; null when the store has no live sync to protect
     */
    public function cursor(int $storeId): ?int
    {
        try {
            if (!$this->webhookNotifier->isSyncEnabled($storeId) || !$this->resendProducts->isAvailable()) {
                return null;
            }

            $connection = $this->resource->getConnection();
            $cursor = $connection->fetchOne(
                $connection->select()
                    ->from($this->resource->getTableName(self::SYNC_STATE_TABLE), ['last_version_id'])
                    ->where('store_id = ?', $storeId)
            );

            return $cursor === false || $cursor === null ? null : (int)$cursor;
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
     * @param int|null $cursor From cursor(), read before the products were loaded
     * @param int[] $productIds
     * @return void
     */
    public function record(int $storeId, ?int $cursor, array $productIds): void
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
     * @param int|null $cursor From cursor(), read before the products were loaded
     * @param int $fromId
     * @param int|null $toId Null for the last page
     * @return void
     */
    public function recordRange(int $storeId, ?int $cursor, int $fromId, ?int $toId): void
    {
        if ($cursor === null) {
            return;
        }

        $this->fetches[] = [$storeId, $cursor, null, $fromId, $toId];
    }

    /**
     * Run once the request has read everything it returns
     *
     * @return void
     */
    public function flush(): void
    {
        $fetches = $this->fetches;
        $this->fetches = [];

        foreach ($fetches as [$storeId, $cursor, $productIds, $fromId, $toId]) {
            try {
                $changed = $productIds === null
                    ? $this->changelogReader->getProductsChangedAfterInRange($cursor, $fromId, $toId)
                    : $this->changelogReader->getProductsChangedAfter($cursor, $productIds);
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
}
