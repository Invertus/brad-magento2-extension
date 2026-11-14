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
use BradSearch\SearchGraphQl\Model\Sync\HeldProducts;
use BradSearch\SearchGraphQl\Model\Sync\IndexState;
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
     * @var IndexState
     */
    private IndexState $indexState;

    /**
     * @var HeldProducts
     */
    private HeldProducts $heldProducts;

    /**
     * @var ResendProducts
     */
    private ResendProducts $resendProducts;

    /**
     * @var int
     */
    private int $maxHoldMinutes;

    /**
     * @var bool
     */
    private bool $holding = false;

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
     * @param IndexState $indexState
     * @param HeldProducts $heldProducts
     * @param ResendProducts $resendProducts
     * @param int $maxHoldMinutes A product held longer than this is sent anyway, with a warning, and sent
     *        again once Magento has indexed it
     */
    public function __construct(
        ChangelogReader $changelogReader,
        WebhookNotifier $webhookNotifier,
        SyncStateFactory $syncStateFactory,
        SyncStateResource $syncStateResource,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger,
        ChangeFilter $changeFilter,
        IndexState $indexState,
        HeldProducts $heldProducts,
        ResendProducts $resendProducts,
        int $maxHoldMinutes = 15
    ) {
        $this->changelogReader = $changelogReader;
        $this->webhookNotifier = $webhookNotifier;
        $this->syncStateFactory = $syncStateFactory;
        $this->syncStateResource = $syncStateResource;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
        $this->changeFilter = $changeFilter;
        $this->indexState = $indexState;
        $this->heldProducts = $heldProducts;
        $this->resendProducts = $resendProducts;
        $this->maxHoldMinutes = $maxHoldMinutes;
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

        $this->holding = $this->holdingAvailable();
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

        $waiting = $this->waitingForIndexes($pending);
        $plans = [];
        foreach ($pending as $storeId => $changes) {
            $plans[$storeId] = $this->plan($storeId, $changes, $waiting[$storeId] ?? []);
        }

        $toFilter = array_filter(array_map(static function (array $plan): array {
            return $plan['send'];
        }, $plans));
        $forced = array_map(static function (array $plan): array {
            return $plan['forced'];
        }, array_intersect_key($plans, $toFilter));
        $filtered = empty($toFilter) ? [] : $this->filterChanges($toFilter, $forced);

        foreach ($pending as $storeId => [$syncState]) {
            try {
                $this->sendStore(
                    $storeId,
                    $syncState,
                    $plans[$storeId],
                    $filtered === null ? null : ($filtered[$storeId] ?? ['changed' => [], 'hashes' => []]),
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
     * Read the changelog product ids a store has not sent yet, plus the products it holds and the ones
     * marked to send again
     *
     * @param int $storeId
     * @param int $currentVersion Current changelog max version
     * @return array{0: SyncState, 1: int[], 2: int[], 3: array<int, bool>, 4: array<int, string>}|null
     *         [state, products to check, of them from the changelog, held => sent at the limit already,
     *         marked => mark token]; null when there is nothing to send
     */
    private function collectStore(int $storeId, int $currentVersion): ?array
    {
        // Get or create sync state for this store
        $syncState = $this->getOrCreateSyncState($storeId, $currentVersion);
        $lastVersionId = $syncState->getLastVersionId();

        $held = $this->holding ? $this->heldProducts->forStore($storeId) : [];
        $resend = $this->resending ? $this->resendProducts->forStore($storeId) : [];
        $others = array_keys($held + array_fill_keys(array_keys($resend), false));

        // Check if there are new changes
        if ($currentVersion <= $lastVersionId) {
            $this->logger->debug('BradSearch Sync: No new changes', [
                'store_id' => $storeId,
                'last_version' => $lastVersionId,
                'current_version' => $currentVersion
            ]);
            return empty($others) ? null : [$syncState, $others, [], $held, $resend];
        }

        // Get changed product IDs
        $productIds = $this->changelogReader->getChangedProductIds($lastVersionId, $currentVersion);

        if (empty($productIds) && empty($others)) {
            // No products in range, but still update version to avoid reprocessing
            $this->updateSyncState($syncState, $currentVersion);
            return null;
        }

        $this->logger->info('BradSearch Sync: Processing changes', [
            'store_id' => $storeId,
            'from_version' => $lastVersionId,
            'to_version' => $currentVersion,
            'product_count' => count($productIds),
            'held_count' => count($held),
            'resend_count' => count($resend)
        ]);

        return [
            $syncState,
            array_values(array_unique(array_merge($productIds, $others))),
            $productIds,
            $held,
            $resend
        ];
    }

    /**
     * Keep only the products whose indexed data changed, for all stores in one pass
     *
     * @param array<int, int[]> $productIdsByStore
     * @param array<int, int[]> $forcedByStore Products to send whatever their hash
     * @return array<int, array{changed: int[], hashes: array<int, string>}>|null Null sends everything
     */
    private function filterChanges(array $productIdsByStore, array $forcedByStore): ?array
    {
        $start = microtime(true);
        try {
            $filtered = $this->changeFilter->filter($productIdsByStore, $forcedByStore);
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
            $counts[$storeId] = count($result['changed']) . '/' . count($productIdsByStore[$storeId]);
        }
        $this->logger->info('BradSearch Sync: Change filter', [
            'changed_per_store' => $counts,
            'filter_ms' => (int)round((microtime(true) - $start) * 1000)
        ]);

        return $filtered;
    }

    /**
     * Holding needs its table; without it the sync sends as it did before, rather than stopping
     *
     * @return bool
     */
    private function holdingAvailable(): bool
    {
        try {
            if ($this->heldProducts->isAvailable()) {
                return true;
            }
            $this->logger->error('BradSearch Sync: Held products table missing, holding nothing (run setup:upgrade)');
        } catch (\Throwable $e) {
            $this->logger->error('BradSearch Sync: Held products table check failed, holding nothing', [
                'error' => $e->getMessage()
            ]);
        }

        return false;
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
     * @param array<int, array{0: SyncState, 1: int[], 2: int[], 3: array<int, bool>, 4: array<int, string>}> $pending
     * @return array<int, array<int, true>> store id => product ids Magento has not indexed yet for that store
     */
    private function waitingForIndexes(array $pending): array
    {
        if (!$this->holding) {
            return [];
        }

        $all = array_values(array_unique(array_merge([], ...array_map(static function (array $changes): array {
            return $changes[1];
        }, array_values($pending)))));

        try {
            $categories = array_fill_keys($this->indexState->notIndexed(IndexState::CATEGORIES, $all), true);
            $ruleWebsites = array_fill_keys($this->indexState->websitesWithPriceRules(), true);
            $rules = empty($ruleWebsites)
                ? []
                : array_fill_keys($this->indexState->notIndexed(IndexState::PRICE_RULES, $all), true);

            $waiting = [];
            foreach ($pending as $storeId => [, $productIds]) {
                $withRules = isset($ruleWebsites[(int)$this->storeManager->getStore($storeId)->getWebsiteId()]);
                foreach ($productIds as $productId) {
                    if (isset($categories[$productId]) || ($withRules && isset($rules[$productId]))) {
                        $waiting[$storeId][$productId] = true;
                    }
                }
            }

            return $waiting;
        } catch (\Throwable $e) {
            // Holding nothing new is how the sync worked before, so a failed check never loses a change.
            // Products already held stay held: one sent at the limit must still be checked once indexed.
            $this->logger->error('BradSearch Sync: Index check failed, holding nothing new', [
                'error' => $e->getMessage()
            ]);
            return array_map(static function (array $changes): array {
                return array_fill_keys(array_keys($changes[3]), true);
            }, $pending);
        }
    }

    /**
     * Decide per product what this run does with it.
     *
     * Not waiting for Magento's index: sent through the filter and no longer held. Waiting: held. Held past
     * the limit: sent once anyway, with a warning, and still held, so it goes through the filter again once
     * Magento has indexed it; until then only a new change or a mark sends it again.
     *
     * @param int $storeId
     * @param array{0: SyncState, 1: int[], 2: int[], 3: array<int, bool>, 4: array<int, string>} $changes
     * @param array<int, true> $waiting
     * @return array<string, mixed> send, forced, hold, release, sentAtLimit (int[] each) and resend
     *         (marked product => token)
     */
    private function plan(int $storeId, array $changes, array $waiting): array
    {
        [, $productIds, $changelogIds, $held, $resend] = $changes;
        $changed = array_flip($changelogIds) + $resend;
        $overdue = array_flip($this->overdue($storeId, $waiting, $held));

        $plan = ['send' => [], 'forced' => [], 'hold' => [], 'release' => [], 'sentAtLimit' => [], 'resend' => $resend];
        foreach ($productIds as $productId) {
            if (!isset($waiting[$productId])) {
                $plan['send'][] = $productId;
                $plan['release'][] = $productId;
            } else {
                $plan['hold'][] = $productId;
                if (!empty($held[$productId]) && isset($changed[$productId])) {
                    $plan['send'][] = $productId;
                } elseif (isset($overdue[$productId])) {
                    $plan['send'][] = $productId;
                    $plan['sentAtLimit'][] = $productId;
                }
            }
        }
        $plan['forced'] = array_values(array_intersect(array_keys($resend), $plan['send']));

        if (!empty($plan['sentAtLimit'])) {
            $this->logger->warning('BradSearch Sync: Sending products Magento has not indexed after the hold limit', [
                'store_id' => $storeId,
                'product_count' => count($plan['sentAtLimit']),
                'product_ids' => array_slice($plan['sentAtLimit'], 0, 20),
                'minutes' => $this->maxHoldMinutes
            ]);
        }
        $reindexed = array_values(array_filter($plan['release'], static function (int $id) use ($held): bool {
            return !empty($held[$id]);
        }));
        if (!empty($reindexed)) {
            $this->logger->info('BradSearch Sync: Indexed products sent at the hold limit, checking them again', [
                'store_id' => $storeId,
                'product_count' => count($reindexed)
            ]);
        }

        return $plan;
    }

    /**
     * @param int $storeId
     * @param array<int, true> $waiting
     * @param array<int, bool> $held
     * @return int[] Waiting products held past the limit and not sent at it yet
     */
    private function overdue(int $storeId, array $waiting, array $held): array
    {
        $candidates = array_filter($held, static function (bool $sentAtLimit, int $id) use ($waiting): bool {
            return !$sentAtLimit && isset($waiting[$id]);
        }, ARRAY_FILTER_USE_BOTH);
        if (empty($candidates)) {
            return [];
        }

        try {
            return array_values(array_filter(
                $this->heldProducts->heldLongerThan($storeId, $this->maxHoldMinutes),
                static function (int $id) use ($candidates): bool {
                    return isset($candidates[$id]);
                }
            ));
        } catch (\Throwable $e) {
            $this->logger->error('BradSearch Sync: Held products age check failed', [
                'store_id' => $storeId,
                'error' => $e->getMessage()
            ]);
            return [];
        }
    }

    /**
     * Notify BradSearch about one store's changed products and move its cursor on success
     *
     * @param int $storeId
     * @param SyncState $syncState
     * @param array<string, mixed> $plan From plan()
     * @param array{changed: int[], hashes: array<int, string>}|null $filtered Null sends every product to send
     * @param int $currentVersion
     * @return void
     */
    private function sendStore(
        int $storeId,
        SyncState $syncState,
        array $plan,
        ?array $filtered,
        int $currentVersion
    ): void {
        $productIds = $filtered === null ? $plan['send'] : $filtered['changed'];

        if (empty($productIds)) {
            $this->moveOn($syncState, $plan, [], $currentVersion);
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
            // Update sync state only on successful notification
            $this->moveOn($syncState, $plan, $productIds, $currentVersion);
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
     * Update the held and marked products, then move the store's cursor past them
     *
     * @param SyncState $syncState
     * @param array<string, mixed> $plan From plan()
     * @param int[] $sent
     * @param int $currentVersion
     * @return void
     */
    private function moveOn(SyncState $syncState, array $plan, array $sent, int $currentVersion): void
    {
        $storeId = (int)$syncState->getStoreId();
        // Written before the cursor moves: a cursor past a product that was never held would lose it.
        if ($this->holding) {
            $this->heldProducts->update($storeId, $plan['release'], $plan['hold'], $plan['sentAtLimit']);
        }
        // Only the marks of products this push carried: BradSearch fetches those again now.
        $sentMarks = array_intersect_key($plan['resend'], array_flip($sent));
        if (!empty($sentMarks)) {
            $this->resendProducts->clear($storeId, array_values($sentMarks));
        }
        $this->updateSyncState($syncState, $currentVersion);

        if (!empty($plan['hold'])) {
            $this->logger->info('BradSearch Sync: Holding products until Magento indexes them', [
                'store_id' => $storeId,
                'product_count' => count($plan['hold'])
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
        // A run that only handles held or marked products must not move the cursor back.
        $syncState->setLastVersionId(max($versionId, (int)$syncState->getLastVersionId()));
        $syncState->setLastSyncAt((new \DateTimeImmutable())->format('Y-m-d H:i:s'));
        $this->syncStateResource->save($syncState);
    }
}
