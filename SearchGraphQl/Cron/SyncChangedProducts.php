<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Cron;

use BradSearch\SearchGraphQl\Model\ResourceModel\SyncState as SyncStateResource;
use BradSearch\SearchGraphQl\Model\SyncState;
use BradSearch\SearchGraphQl\Model\SyncStateFactory;
use BradSearch\SearchGraphQl\Model\Sync\ChangeFilter;
use BradSearch\SearchGraphQl\Model\Sync\ChangelogReader;
use BradSearch\SearchGraphQl\Model\Sync\ResendProducts;
use BradSearch\SearchGraphQl\Model\Sync\WebhookNotifier;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Cron Job: Sync Changed Products
 *
 * Runs every minute to detect product changes via MView changelog
 * and send webhook notifications to BradSearch Laravel backend.
 */
class SyncChangedProducts
{
    /**
     * @var ChangelogReader
     */
    private ChangelogReader $changelogReader;

    /**
     * @var WebhookNotifier
     */
    private WebhookNotifier $webhookNotifier;

    /**
     * @var SyncStateFactory
     */
    private SyncStateFactory $syncStateFactory;

    /**
     * @var SyncStateResource
     */
    private SyncStateResource $syncStateResource;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var ChangeFilter
     */
    private ChangeFilter $changeFilter;

    /**
     * @var ResendProducts
     */
    private ResendProducts $resendProducts;

    /**
     * @var bool
     */
    private bool $resending = false;

    /**
     * @param ChangelogReader $changelogReader
     * @param WebhookNotifier $webhookNotifier
     * @param SyncStateFactory $syncStateFactory
     * @param SyncStateResource $syncStateResource
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     * @param ChangeFilter $changeFilter
     * @param ResendProducts $resendProducts
     */
    public function __construct(
        ChangelogReader $changelogReader,
        WebhookNotifier $webhookNotifier,
        SyncStateFactory $syncStateFactory,
        SyncStateResource $syncStateResource,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        ChangeFilter $changeFilter,
        ResendProducts $resendProducts
    ) {
        $this->changelogReader = $changelogReader;
        $this->webhookNotifier = $webhookNotifier;
        $this->syncStateFactory = $syncStateFactory;
        $this->syncStateResource = $syncStateResource;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
        $this->changeFilter = $changeFilter;
        $this->resendProducts = $resendProducts;
    }

    /**
     * Execute cron job
     *
     * Piggybacks on ElasticSuite by reading from the same catalogsearch_fulltext_cl
     * changelog table. Syncs products that have changed since our last sync.
     *
     * @return void
     */
    public function execute(): void
    {
        // Check if changelog is available
        if (!$this->changelogReader->isChangelogAvailable()) {
            $this->logger->warning('BradSearch Sync: Changelog table not available');
            return;
        }

        // Get current changelog version
        $currentVersion = $this->changelogReader->getCurrentVersion();
        if ($currentVersion === 0) {
            $this->logger->debug('BradSearch Sync: No changelog entries found');
            return;
        }

        $this->resending = $this->resendingAvailable();

        // Process each store that has sync enabled
        $pending = [];
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int)$store->getId();

            // Check if sync is enabled for this store
            if (!$this->webhookNotifier->isSyncEnabled($storeId)) {
                continue;
            }

            try {
                $changes = $this->collectStore($storeId, $currentVersion);
                if ($changes !== null) {
                    $pending[$storeId] = $changes;
                }
            } catch (\Exception $e) {
                $this->logger->error('BradSearch Sync: Error processing store', [
                    'store_id' => $storeId,
                    'error' => $e->getMessage()
                ]);
            }
        }

        if (empty($pending)) {
            return;
        }

        $filtered = $this->filterChanges($pending);

        foreach ($pending as $storeId => [$syncState, $productIds, $resend]) {
            try {
                $this->sendStore(
                    $storeId,
                    $syncState,
                    $productIds,
                    $filtered === null ? null : ($filtered[$storeId] ?? ['changed' => [], 'hashes' => []]),
                    $resend,
                    $currentVersion
                );
            } catch (\Exception $e) {
                $this->logger->error('BradSearch Sync: Error processing store', [
                    'store_id' => $storeId,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Read the changelog product ids a store has not sent yet, plus the products marked to send again
     *
     * @param int $storeId
     * @param int $currentVersion Current changelog max version
     * @return array{0: SyncState, 1: int[], 2: array<int, string>}|null [state, products to check, marked
     *         products => mark token]; null when there is nothing to send
     */
    private function collectStore(int $storeId, int $currentVersion): ?array
    {
        // Get or create sync state for this store
        $syncState = $this->getOrCreateSyncState($storeId, $currentVersion);
        $lastVersionId = $syncState->getLastVersionId();

        $resend = $this->resending ? $this->resendProducts->forStore($storeId) : [];

        // Check if there are new changes
        if ($currentVersion <= $lastVersionId) {
            $this->logger->debug('BradSearch Sync: No new changes', [
                'store_id' => $storeId,
                'last_version' => $lastVersionId,
                'current_version' => $currentVersion
            ]);
            return empty($resend) ? null : [$syncState, array_keys($resend), $resend];
        }

        // Get changed product IDs
        $productIds = $this->changelogReader->getChangedProductIds($lastVersionId, $currentVersion);

        if (empty($productIds) && empty($resend)) {
            // No products in range, but still update version to avoid reprocessing
            $this->updateSyncState($syncState, $currentVersion);
            return null;
        }

        $this->logger->info('BradSearch Sync: Processing changes', [
            'store_id' => $storeId,
            'from_version' => $lastVersionId,
            'to_version' => $currentVersion,
            'product_count' => count($productIds),
            'resend_count' => count($resend)
        ]);

        return [$syncState, array_values(array_unique(array_merge($productIds, array_keys($resend)))), $resend];
    }

    /**
     * Keep only the products whose indexed data changed, for all stores in one pass
     *
     * @param array<int, array{0: SyncState, 1: int[], 2: array<int, string>}> $pending
     * @return array<int, array{changed: int[], hashes: array<int, string>}>|null Null sends everything
     */
    private function filterChanges(array $pending): ?array
    {
        $start = microtime(true);
        try {
            $filtered = $this->changeFilter->filter(
                array_map(static function (array $changes): array {
                    return $changes[1];
                }, $pending),
                array_map(static function (array $changes): array {
                    return array_keys($changes[2]);
                }, $pending)
            );
        } catch (\Throwable $e) {
            // Sending every changelog product is how the sync worked before the filter, so a broken
            // filter costs extra work in BradSearch, never a lost change.
            $this->logger->error('BradSearch Sync: Change filter failed, sending all changelog products', [
                'error' => $e->getMessage()
            ]);
            return null;
        }

        $counts = [];
        foreach ($filtered as $storeId => $result) {
            $counts[$storeId] = count($result['changed']) . '/' . count($pending[$storeId][1]);
        }
        $this->logger->info('BradSearch Sync: Change filter', [
            'changed_per_store' => $counts,
            'filter_ms' => (int)round((microtime(true) - $start) * 1000)
        ]);

        return $filtered;
    }

    /**
     * Notify BradSearch about one store's changed products and move its cursor on success
     *
     * @param int $storeId
     * @param SyncState $syncState
     * @param int[] $productIds Changelog and marked product ids
     * @param array{changed: int[], hashes: array<int, string>}|null $filtered Null sends every product
     * @param array<int, string> $resend Marked products => mark token
     * @param int $currentVersion
     * @return void
     */
    private function sendStore(
        int $storeId,
        SyncState $syncState,
        array $productIds,
        ?array $filtered,
        array $resend,
        int $currentVersion
    ): void {
        if ($filtered !== null) {
            $productIds = $filtered['changed'];
        }

        if (empty($productIds)) {
            $this->updateSyncState($syncState, $currentVersion);
            return;
        }

        // Send webhook notification
        $success = $this->webhookNotifier->notify($productIds, $storeId, $currentVersion, $filtered !== null);

        if ($success) {
            // A stored hash means BradSearch was told, so it is written only after the 2xx.
            if ($filtered !== null) {
                $this->changeFilter->store($filtered['hashes'], $storeId);
            } else {
                // Sent without the filter: BradSearch now holds what it fetches, not what the stored hash
                // says, so a later change back to the stored state must not be dropped.
                $this->changeFilter->forget($productIds, $storeId);
            }
            // Only the marks of products this push carried: BradSearch fetches those again now.
            $sentMarks = array_intersect_key($resend, array_flip($productIds));
            if (!empty($sentMarks)) {
                $this->resendProducts->clear($storeId, array_values($sentMarks));
            }
            // Update sync state only on successful notification
            $this->updateSyncState($syncState, $currentVersion);
            $this->logger->info('BradSearch Sync: Successfully notified', [
                'store_id' => $storeId,
                'product_count' => count($productIds),
                'new_version' => $currentVersion
            ]);
        } else {
            // Don't update version on failure - will retry next cron run
            $this->logger->warning('BradSearch Sync: Notification failed, will retry', [
                'store_id' => $storeId,
                'product_count' => count($productIds)
            ]);
        }
    }

    /**
     * Resending needs its table; without it the sync works as before, rather than stopping
     *
     * @return bool
     */
    private function resendingAvailable(): bool
    {
        try {
            if ($this->resendProducts->isAvailable()) {
                return true;
            }
            $this->logger->error('BradSearch Sync: Resend table missing, resending nothing (run setup:upgrade)');
        } catch (\Throwable $e) {
            $this->logger->error('BradSearch Sync: Resend table check failed, resending nothing', [
                'error' => $e->getMessage()
            ]);
        }

        return false;
    }

    /**
     * Get or create sync state for a store
     *
     * When creating a new sync state, initialize from current changelog version.
     * Assumes full sync already performed on BradSearch backend.
     *
     * @param int $storeId
     * @param int $currentVersion Current changelog max version
     * @return SyncState
     */
    private function getOrCreateSyncState(int $storeId, int $currentVersion): SyncState
    {
        /** @var SyncState $syncState */
        $syncState = $this->syncStateFactory->create();
        $this->syncStateResource->loadByStoreId($syncState, $storeId);

        if (!$syncState->getId()) {
            // Initialize from current version to skip historical changes
            // Assumes full sync already performed on BradSearch backend
            $syncState->setStoreId($storeId);
            $syncState->setLastVersionId($currentVersion);
            $syncState->setLastSyncAt((new \DateTimeImmutable())->format('Y-m-d H:i:s'));

            // CRITICAL: Save the initial state to database (this was the bug!)
            $this->syncStateResource->save($syncState);

            $this->logger->info('BradSearch Sync: Initialized new sync state from current version', [
                'store_id' => $storeId,
                'initial_version' => $currentVersion
            ]);
        }

        return $syncState;
    }

    /**
     * Update sync state with new version
     *
     * @param SyncState $syncState
     * @param int $versionId
     * @return void
     */
    private function updateSyncState(SyncState $syncState, int $versionId): void
    {
        // A run that only resends marked products must not move the cursor back.
        $syncState->setLastVersionId(max($versionId, (int)$syncState->getLastVersionId()));
        $syncState->setLastSyncAt((new \DateTimeImmutable())->format('Y-m-d H:i:s'));
        $this->syncStateResource->save($syncState);
    }
}
