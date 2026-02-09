<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Cron;

use BradSearch\SearchGraphQl\Model\ResourceModel\SyncState as SyncStateResource;
use BradSearch\SearchGraphQl\Model\SyncState;
use BradSearch\SearchGraphQl\Model\SyncStateFactory;
use BradSearch\SearchGraphQl\Model\Sync\ChangelogReader;
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
     * @param ChangelogReader $changelogReader
     * @param WebhookNotifier $webhookNotifier
     * @param SyncStateFactory $syncStateFactory
     * @param SyncStateResource $syncStateResource
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        ChangelogReader $changelogReader,
        WebhookNotifier $webhookNotifier,
        SyncStateFactory $syncStateFactory,
        SyncStateResource $syncStateResource,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->changelogReader = $changelogReader;
        $this->webhookNotifier = $webhookNotifier;
        $this->syncStateFactory = $syncStateFactory;
        $this->syncStateResource = $syncStateResource;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
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

        // Process each store that has sync enabled
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int)$store->getId();

            // Check if sync is enabled for this store
            if (!$this->webhookNotifier->isSyncEnabled($storeId)) {
                continue;
            }

            try {
                $this->processStore($storeId, $currentVersion);
            } catch (\Exception $e) {
                $this->logger->error('BradSearch Sync: Error processing store', [
                    'store_id' => $storeId,
                    'error' => $e->getMessage()
                ]);
            }
        }
    }

    /**
     * Process sync for a specific store
     *
     * @param int $storeId
     * @param int $currentVersion Current changelog max version
     * @return void
     */
    private function processStore(int $storeId, int $currentVersion): void
    {
        // Get or create sync state for this store
        $syncState = $this->getOrCreateSyncState($storeId, $currentVersion);
        $lastVersionId = $syncState->getLastVersionId();

        // Check if there are new changes
        if ($currentVersion <= $lastVersionId) {
            $this->logger->debug('BradSearch Sync: No new changes', [
                'store_id' => $storeId,
                'last_version' => $lastVersionId,
                'current_version' => $currentVersion
            ]);
            return;
        }

        // Get changed product IDs
        $productIds = $this->changelogReader->getChangedProductIds($lastVersionId, $currentVersion);

        if (empty($productIds)) {
            // No products in range, but still update version to avoid reprocessing
            $this->updateSyncState($syncState, $currentVersion);
            return;
        }

        $this->logger->info('BradSearch Sync: Processing changes', [
            'store_id' => $storeId,
            'from_version' => $lastVersionId,
            'to_version' => $currentVersion,
            'product_count' => count($productIds)
        ]);

        // Send webhook notification
        $success = $this->webhookNotifier->notify($productIds, $storeId, $currentVersion);

        if ($success) {
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
        $syncState->setLastVersionId($versionId);
        $syncState->setLastSyncAt((new \DateTimeImmutable())->format('Y-m-d H:i:s'));
        $this->syncStateResource->save($syncState);
    }
}
