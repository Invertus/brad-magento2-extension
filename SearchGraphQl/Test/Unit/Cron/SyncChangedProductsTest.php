<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Cron;

use BradSearch\SearchGraphQl\Cron\SyncChangedProducts;
use BradSearch\SearchGraphQl\Model\ResourceModel\SyncState as SyncStateResource;
use BradSearch\SearchGraphQl\Model\SyncState;
use BradSearch\SearchGraphQl\Model\SyncStateFactory;
use BradSearch\SearchGraphQl\Model\Sync\ChangeFilter;
use BradSearch\SearchGraphQl\Model\Sync\ChangelogReader;
use BradSearch\SearchGraphQl\Model\Sync\HeldProducts;
use BradSearch\SearchGraphQl\Model\Sync\IndexState;
use BradSearch\SearchGraphQl\Model\Sync\ResendProducts;
use BradSearch\SearchGraphQl\Model\Sync\WebhookNotifier;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

class SyncChangedProductsTest extends TestCase
{
    private const CURRENT_VERSION = 500;

    /** @var ChangelogReader&MockObject */
    private $changelogReader;

    /** @var WebhookNotifier&MockObject */
    private $notifier;

    /** @var SyncStateResource&MockObject */
    private $syncStateResource;

    /** @var ChangeFilter&MockObject */
    private $changeFilter;

    /** @var IndexState&MockObject */
    private $indexState;

    /** @var HeldProducts&MockObject */
    private $heldProducts;

    /** @var ResendProducts&MockObject */
    private $resendProducts;

    /** @var array<int, array<int, string>> store id => marked product => token */
    private array $marks = [];

    /** @var array<int, int> store id => saved cursor */
    private array $savedVersions = [];

    /** @var int The cursor every store starts the run with */
    private int $lastVersion = 400;

    /** @var int[] What the changelog returns for versions 400..500 */
    private array $changelogIds = [10, 11, 12];

    /** @var array<int, array<int, bool>> store id => held product => sent at the limit already */
    private array $held = [];

    /** @var array<string, int[]> view id => products that view has not indexed */
    private array $notIndexed = [];

    /** @var array<int, array{0: string, 1: int[]}> notIndexed() calls */
    private array $indexChecks = [];

    /** @var int[] Websites with an active catalog price rule */
    private array $ruleWebsites = [];

    /** @var array<int, int> store id => website id (default 1) */
    private array $websites = [];

    /** @var array<int, int[]> store id => products held past the limit */
    private array $overdue = [];

    /** @var array<int, array{0: int[], 1: int[]}> store id => [released, held] */
    private array $heldUpdates = [];

    /** @var array<int, int[]> store id => products marked as sent at the hold limit */
    private array $sentAtLimit = [];

    /** @var array<int, array{0: string, 1: string, 2: array}> */
    private array $logs = [];

    protected function setUp(): void
    {
        $this->changelogReader = $this->createMock(ChangelogReader::class);
        $this->changelogReader->method('isChangelogAvailable')->willReturn(true);
        $this->changelogReader->method('getCurrentVersion')->willReturn(self::CURRENT_VERSION);
        $this->changelogReader->method('getChangedProductIds')->with(400, self::CURRENT_VERSION)
            ->willReturnCallback(function (): array {
                return $this->changelogIds;
            });

        $this->notifier = $this->createMock(WebhookNotifier::class);
        $this->notifier->method('isSyncEnabled')->willReturn(true);

        $this->syncStateResource = $this->createMock(SyncStateResource::class);
        $this->syncStateResource->method('save')->willReturnCallback(function (SyncState $state) {
            $this->savedVersions[$state->getStoreId()] = $state->getLastVersionId();
            return $this->syncStateResource;
        });

        $this->changeFilter = $this->createMock(ChangeFilter::class);

        $this->indexState = $this->createMock(IndexState::class);
        $this->indexState->method('notIndexed')->willReturnCallback(
            function (string $viewId, array $productIds): array {
                $this->indexChecks[] = [$viewId, $productIds];
                return $this->notIndexed[$viewId] ?? [];
            }
        );
        $this->indexState->method('websitesWithPriceRules')->willReturnCallback(function (): array {
            return $this->ruleWebsites;
        });

        $this->heldProducts = $this->heldProductsMock(true);

        $this->resendProducts = $this->createMock(ResendProducts::class);
        $this->resendProducts->method('isAvailable')->willReturn(true);
        $this->resendProducts->method('forStore')->willReturnCallback(function (int $storeId): array {
            return $this->marks[$storeId] ?? [];
        });
    }

    public function testSendsOnlyChangedProductsAndStoresTheirHashesAfterTheNotificationSucceeded(): void
    {
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [10, 11, 12]])
            ->willReturn([1 => ['changed' => [11], 'hashes' => [11 => 'h11']]]);
        $this->notifier->expects($this->once())->method('notify')->with([11], 1, self::CURRENT_VERSION, true)->willReturn(true);
        $this->changeFilter->expects($this->once())->method('store')->with([11 => 'h11'], 1);

        $this->cron([1])->execute();

        $this->assertSame([1 => self::CURRENT_VERSION], $this->savedVersions);
    }

    public function testAFailedNotificationStoresNoHashAndKeepsTheCursor(): void
    {
        $this->changeFilter->method('filter')->willReturn([1 => ['changed' => [11], 'hashes' => [11 => 'h11']]]);
        $this->notifier->method('notify')->willReturn(false);
        $this->changeFilter->expects($this->never())->method('store');

        $this->cron([1])->execute();

        $this->assertSame([], $this->savedVersions);
    }

    public function testNoChangedProductMovesTheCursorWithoutNotifying(): void
    {
        $this->changeFilter->method('filter')->willReturn([1 => ['changed' => [], 'hashes' => []]]);
        $this->notifier->expects($this->never())->method('notify');
        $this->changeFilter->expects($this->never())->method('store');

        $this->cron([1])->execute();

        $this->assertSame([1 => self::CURRENT_VERSION], $this->savedVersions);
    }

    public function testABrokenFilterSendsEveryProductAndForgetsTheirHashes(): void
    {
        $this->changeFilter->method('filter')->willThrowException(new \RuntimeException('table missing'));
        $this->notifier->expects($this->once())->method('notify')->with([10, 11, 12], 1, self::CURRENT_VERSION, false)
            ->willReturn(true);
        $this->changeFilter->expects($this->never())->method('store');
        // BradSearch now holds what it fetches, so a later change back to the stored state must still be sent.
        $this->changeFilter->expects($this->once())->method('forget')->with([10, 11, 12], 1);

        $this->cron([1])->execute();

        $this->assertSame([1 => self::CURRENT_VERSION], $this->savedVersions);
    }

    public function testABrokenFilterWithAFailedNotificationForgetsNothing(): void
    {
        $this->changeFilter->method('filter')->willThrowException(new \RuntimeException('db gone'));
        $this->notifier->method('notify')->willReturn(false);
        $this->changeFilter->expects($this->never())->method('forget');

        $this->cron([1])->execute();

        $this->assertSame([], $this->savedVersions);
    }

    public function testAFailedForgetKeepsTheCursorSoTheProductsAreSentAgain(): void
    {
        $this->changeFilter->method('filter')->willThrowException(new \RuntimeException('filter broke'));
        $this->notifier->method('notify')->willReturn(true);
        $this->changeFilter->method('forget')->willThrowException(new \RuntimeException('db gone'));

        $this->cron([1])->execute();

        $this->assertSame([], $this->savedVersions);
    }

    public function testMarkedProductsAreSentWhateverTheirHashAndTheirMarksClearedAfterTheNotification(): void
    {
        $this->marks[1] = [11 => 't11', 20 => 't20'];
        $this->changeFilter->expects($this->once())->method('filter')
            ->with([1 => [10, 11, 12, 20]], [1 => [11, 20]])
            ->willReturn([1 => ['changed' => [11, 20], 'hashes' => [11 => 'h11', 20 => 'h20']]]);
        $this->notifier->expects($this->once())->method('notify')->with([11, 20], 1, self::CURRENT_VERSION, true)
            ->willReturn(true);
        $this->resendProducts->expects($this->once())->method('clear')->with(1, ['t11', 't20']);

        $this->cron([1])->execute();

        $this->assertSame([1 => self::CURRENT_VERSION], $this->savedVersions);
    }

    public function testAFailedNotificationKeepsTheMarks(): void
    {
        $this->marks[1] = [11 => 't11'];
        $this->changeFilter->method('filter')->willReturn([1 => ['changed' => [11], 'hashes' => [11 => 'h11']]]);
        $this->notifier->method('notify')->willReturn(false);
        $this->resendProducts->expects($this->never())->method('clear');

        $this->cron([1])->execute();

        $this->assertSame([], $this->savedVersions);
    }

    public function testMarkedProductsAreSentWhenTheChangelogHasNothingNewAndTheCursorStays(): void
    {
        $this->lastVersion = self::CURRENT_VERSION;
        $this->marks[1] = [11 => 't11'];
        $this->changelogReader->expects($this->never())->method('getChangedProductIds');
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [11]], [1 => [11]])
            ->willReturn([1 => ['changed' => [11], 'hashes' => [11 => 'h11']]]);
        $this->notifier->expects($this->once())->method('notify')->willReturn(true);
        $this->resendProducts->expects($this->once())->method('clear')->with(1, ['t11']);

        $this->cron([1])->execute();

        $this->assertSame([1 => self::CURRENT_VERSION], $this->savedVersions);
    }

    public function testAMarkedProductWaitingForTheIndexIsHeldAndKeepsItsMark(): void
    {
        $this->marks[1] = [11 => 't11'];
        $this->notIndexed[IndexState::CATEGORIES] = [11];
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [10, 12]], [1 => []])
            ->willReturn([1 => ['changed' => [10], 'hashes' => [10 => 'a']]]);
        $this->notifier->method('notify')->willReturn(true);
        $this->resendProducts->expects($this->never())->method('clear');

        $this->cron([1])->execute();

        $this->assertSame([1 => [[10, 12], [11]]], $this->heldUpdates);
    }

    public function testWithoutTheResendTableTheSyncWorksAsBefore(): void
    {
        $this->resendProducts = $this->createMock(ResendProducts::class);
        $this->resendProducts->method('isAvailable')->willReturn(false);
        $this->resendProducts->expects($this->never())->method('forStore');
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [10, 11, 12]], [1 => []])
            ->willReturn([1 => ['changed' => [11], 'hashes' => [11 => 'h11']]]);
        $this->notifier->expects($this->once())->method('notify')->willReturn(true);

        $this->cron([1])->execute();

        $this->assertSame([1 => self::CURRENT_VERSION], $this->savedVersions);
    }

    public function testAllStoresAreFilteredInOnePassAndSentSeparately(): void
    {
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [10, 11, 12], 2 => [10, 11, 12]])
            ->willReturn([
                1 => ['changed' => [10], 'hashes' => [10 => 'a']],
                2 => ['changed' => [12], 'hashes' => [12 => 'b']],
            ]);
        $sent = [];
        $this->notifier->method('notify')->willReturnCallback(function (array $ids, int $storeId) use (&$sent): bool {
            $sent[$storeId] = $ids;
            return true;
        });
        $stored = [];
        $this->changeFilter->method('store')->willReturnCallback(function (array $hashes, int $storeId) use (&$stored): void {
            $stored[$storeId] = $hashes;
        });

        $this->cron([1, 2])->execute();

        $this->assertSame([1 => [10], 2 => [12]], $sent);
        $this->assertSame([1 => [10 => 'a'], 2 => [12 => 'b']], $stored);
    }

    public function testAProductWaitingForTheCategoryIndexIsHeldAndTheOthersAreSent(): void
    {
        $this->notIndexed[IndexState::CATEGORIES] = [11];
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [10, 12]])
            ->willReturn([1 => ['changed' => [10, 12], 'hashes' => [10 => 'a', 12 => 'c']]]);
        $this->notifier->expects($this->once())->method('notify')->with([10, 12], 1, self::CURRENT_VERSION, true)
            ->willReturn(true);
        $this->changeFilter->expects($this->once())->method('store')->with([10 => 'a', 12 => 'c'], 1);

        $this->cron([1])->execute();

        $this->assertSame([1 => [[10, 12], [11]]], $this->heldUpdates);
        $this->assertSame([1 => self::CURRENT_VERSION], $this->savedVersions);
    }

    public function testAHeldProductIsSentOnceItsCategoriesAreIndexed(): void
    {
        $this->held[1] = [20 => false];
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [10, 11, 12, 20]])
            ->willReturn([1 => ['changed' => [20], 'hashes' => [20 => 'h20']]]);
        $this->notifier->expects($this->once())->method('notify')->with([20], 1, self::CURRENT_VERSION, true)
            ->willReturn(true);
        $this->changeFilter->expects($this->once())->method('store')->with([20 => 'h20'], 1);

        $this->cron([1])->execute();

        $this->assertSame([1 => [[10, 11, 12, 20], []]], $this->heldUpdates);
        $this->assertSame([[IndexState::CATEGORIES, [10, 11, 12, 20]]], $this->indexChecks);
    }

    public function testAHeldProductIsSentWhenNothingNewIsInTheChangelog(): void
    {
        $this->lastVersion = self::CURRENT_VERSION;
        $this->held[1] = [20 => false];
        $this->changelogReader->expects($this->never())->method('getChangedProductIds');
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [20]])
            ->willReturn([1 => ['changed' => [20], 'hashes' => [20 => 'h20']]]);
        $this->notifier->expects($this->once())->method('notify')->with([20])->willReturn(true);

        $this->cron([1])->execute();

        $this->assertSame([1 => [[20], []]], $this->heldUpdates);
        $this->assertSame([1 => self::CURRENT_VERSION], $this->savedVersions);
    }

    public function testHeldProductsAreCheckedAgainWhenTheChangelogRangeHasNoProducts(): void
    {
        $this->changelogIds = [];
        $this->held[1] = [20 => false];
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [20]])
            ->willReturn([1 => ['changed' => [20], 'hashes' => [20 => 'h20']]]);
        $this->notifier->expects($this->once())->method('notify')->with([20])->willReturn(true);

        $this->cron([1])->execute();

        $this->assertSame([1 => [[20], []]], $this->heldUpdates);
        $this->assertSame([1 => self::CURRENT_VERSION], $this->savedVersions);
    }

    public function testOnlyWaitingProductsSendsNothingAndStillMovesTheCursor(): void
    {
        $this->notIndexed[IndexState::CATEGORIES] = [10, 11, 12];
        $this->changeFilter->expects($this->never())->method('filter');
        $this->notifier->expects($this->never())->method('notify');

        $this->cron([1])->execute();

        $this->assertSame([1 => [[], [10, 11, 12]]], $this->heldUpdates);
        $this->assertSame([1 => self::CURRENT_VERSION], $this->savedVersions);
    }

    public function testAFailedNotificationLeavesTheHeldProductsAsTheyWere(): void
    {
        $this->notIndexed[IndexState::CATEGORIES] = [11];
        $this->changeFilter->method('filter')->willReturn([1 => ['changed' => [10], 'hashes' => [10 => 'a']]]);
        $this->notifier->method('notify')->willReturn(false);
        $this->heldProducts->expects($this->never())->method('update');

        $this->cron([1])->execute();

        $this->assertSame([], $this->savedVersions);
    }

    public function testABrokenIndexCheckHoldsNothingNew(): void
    {
        $indexState = $this->createMock(IndexState::class);
        $indexState->method('notIndexed')->willThrowException(new \RuntimeException('no mview_state'));
        $this->indexState = $indexState;
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [10, 11, 12]])
            ->willReturn([1 => ['changed' => [10, 11, 12], 'hashes' => [10 => 'a', 11 => 'b', 12 => 'c']]]);
        $this->notifier->expects($this->once())->method('notify')->with([10, 11, 12])->willReturn(true);

        $this->cron([1])->execute();

        $this->assertSame([1 => [[10, 11, 12], []]], $this->heldUpdates);
    }

    public function testABrokenIndexCheckKeepsTheProductsAlreadyHeld(): void
    {
        $this->held[1] = [20 => true, 21 => false];
        $indexState = $this->createMock(IndexState::class);
        $indexState->method('notIndexed')->willThrowException(new \RuntimeException('db gone'));
        $this->indexState = $indexState;
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [10, 11, 12]])
            ->willReturn([1 => ['changed' => [10], 'hashes' => [10 => 'a']]]);
        $this->notifier->method('notify')->willReturn(true);

        $this->cron([1])->execute();

        $this->assertSame([1 => [[10, 11, 12], [20, 21]]], $this->heldUpdates);
    }

    public function testWithoutTheHeldTableNothingIsHeldAndEverythingReadyIsSent(): void
    {
        $this->heldProducts = $this->heldProductsMock(false);
        $this->heldProducts->expects($this->never())->method('forStore');
        $this->heldProducts->expects($this->never())->method('update');
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [10, 11, 12]])
            ->willReturn([1 => ['changed' => [11], 'hashes' => [11 => 'h11']]]);
        $this->notifier->expects($this->once())->method('notify')->with([11])->willReturn(true);

        $this->cron([1])->execute();

        $this->assertSame([], $this->indexChecks);
        $this->assertSame([1 => self::CURRENT_VERSION], $this->savedVersions);
    }

    public function testAProductHeldPastTheLimitIsSentWithAWarningAndStaysHeld(): void
    {
        $this->held[1] = [11 => false];
        $this->notIndexed[IndexState::CATEGORIES] = [11, 12];
        $this->overdue[1] = [11];
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [10, 11]])
            ->willReturn([1 => ['changed' => [11], 'hashes' => [11 => 'h11']]]);
        $this->notifier->expects($this->once())->method('notify')->with([11])->willReturn(true);

        $this->cron([1])->execute();

        // Still held: once Magento has indexed it, it goes through the filter again.
        $this->assertSame([1 => [[10], [11, 12]]], $this->heldUpdates);
        $this->assertSame([1 => [11]], $this->sentAtLimit);
        $warnings = $this->logsOf('warning');
        $this->assertCount(1, $warnings);
        $this->assertSame([11], $warnings[0][2]['product_ids']);
    }

    public function testAProductHeldPastTheLimitWithAnUnchangedHashIsStillMarkedAsSentAtTheLimit(): void
    {
        $this->lastVersion = self::CURRENT_VERSION;
        $this->held[1] = [11 => false];
        $this->notIndexed[IndexState::CATEGORIES] = [11];
        $this->overdue[1] = [11];
        $this->changeFilter->method('filter')->willReturn([1 => ['changed' => [], 'hashes' => []]]);
        $this->notifier->expects($this->never())->method('notify');

        $this->cron([1])->execute();

        $this->assertSame([1 => [[], [11]]], $this->heldUpdates);
        $this->assertSame([1 => [11]], $this->sentAtLimit);
    }

    public function testAProductSentAtTheLimitIsNotSentAgainWhileMagentoStillHasNotIndexedIt(): void
    {
        $this->lastVersion = self::CURRENT_VERSION;
        $this->held[1] = [20 => true];
        $this->notIndexed[IndexState::CATEGORIES] = [20];
        $this->overdue[1] = [20];
        $this->changeFilter->expects($this->never())->method('filter');
        $this->notifier->expects($this->never())->method('notify');

        $this->cron([1])->execute();

        $this->assertSame([1 => [[], [20]]], $this->heldUpdates);
        $this->assertSame([], $this->sentAtLimit);
        $this->assertSame([], $this->logsOf('warning'));
    }

    public function testAProductSentAtTheLimitWithANewChangeIsSentAgainAndStaysHeld(): void
    {
        $this->held[1] = [11 => true];
        $this->notIndexed[IndexState::CATEGORIES] = [11];
        $this->overdue[1] = [11];
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [10, 11, 12]])
            ->willReturn([1 => ['changed' => [11], 'hashes' => [11 => 'h11']]]);
        $this->notifier->expects($this->once())->method('notify')->with([11])->willReturn(true);

        $this->cron([1])->execute();

        $this->assertSame([1 => [[10, 12], [11]]], $this->heldUpdates);
        $this->assertSame([], $this->logsOf('warning'));
    }

    public function testAProductSentAtTheLimitGoesThroughTheFilterAgainOnceIndexed(): void
    {
        $this->lastVersion = self::CURRENT_VERSION;
        $this->held[1] = [20 => true];
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [20]], [1 => []])
            ->willReturn([1 => ['changed' => [20], 'hashes' => [20 => 'h20']]]);
        $this->notifier->expects($this->once())->method('notify')->with([20])->willReturn(true);

        $this->cron([1])->execute();

        $this->assertSame([1 => [[20], []]], $this->heldUpdates);
        $this->assertCount(1, array_filter($this->logsOf('info'), static function (array $log): bool {
            return strpos($log[1], 'Indexed products sent at the hold limit') !== false;
        }));
    }

    public function testAMarkedProductSentAtTheLimitIsSentWhateverItsHash(): void
    {
        $this->lastVersion = self::CURRENT_VERSION;
        $this->held[1] = [11 => true];
        $this->marks[1] = [11 => 't11'];
        $this->notIndexed[IndexState::CATEGORIES] = [11];
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [11]], [1 => [11]])
            ->willReturn([1 => ['changed' => [11], 'hashes' => [11 => 'h11']]]);
        $this->notifier->expects($this->once())->method('notify')->with([11])->willReturn(true);
        $this->resendProducts->expects($this->once())->method('clear')->with(1, ['t11']);

        $this->cron([1])->execute();

        $this->assertSame([1 => [[], [11]]], $this->heldUpdates);
    }

    public function testThePriceRuleIndexIsOnlyWaitedForOnWebsitesWithActiveRules(): void
    {
        $this->websites = [1 => 1, 9 => 9];
        $this->ruleWebsites = [9];
        $this->notIndexed[IndexState::PRICE_RULES] = [11];
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [10, 11, 12], 9 => [10, 12]])
            ->willReturn([1 => ['changed' => [], 'hashes' => []], 9 => ['changed' => [], 'hashes' => []]]);

        $this->cron([1, 9])->execute();

        $this->assertSame([1 => [[10, 11, 12], []], 9 => [[10, 12], [11]]], $this->heldUpdates);
    }

    public function testWithoutActivePriceRulesTheRuleIndexIsNotRead(): void
    {
        $this->changeFilter->method('filter')->willReturn([1 => ['changed' => [], 'hashes' => []]]);

        $this->cron([1])->execute();

        $this->assertSame([IndexState::CATEGORIES], array_column($this->indexChecks, 0));
    }

    /**
     * @param bool $available
     * @return HeldProducts&MockObject
     */
    private function heldProductsMock(bool $available)
    {
        $heldProducts = $this->createMock(HeldProducts::class);
        $heldProducts->method('isAvailable')->willReturn($available);
        $heldProducts->method('forStore')->willReturnCallback(function (int $storeId): array {
            return $this->held[$storeId] ?? [];
        });
        $heldProducts->method('heldLongerThan')->willReturnCallback(function (int $storeId, int $minutes): array {
            $this->assertSame(15, $minutes);
            return $this->overdue[$storeId] ?? [];
        });
        $heldProducts->method('update')->willReturnCallback(
            function (int $storeId, array $release, array $hold, array $sentAtLimit = []): void {
                $this->heldUpdates[$storeId] = [$release, $hold];
                if (!empty($sentAtLimit)) {
                    $this->sentAtLimit[$storeId] = $sentAtLimit;
                }
            }
        );

        return $heldProducts;
    }

    /**
     * @param int[] $storeIds
     * @return SyncChangedProducts
     */
    private function cron(array $storeIds): SyncChangedProducts
    {
        $stores = [];
        foreach ($storeIds as $storeId) {
            $store = $this->createMock(Store::class);
            $store->method('getId')->willReturn($storeId);
            $store->method('getWebsiteId')->willReturn($this->websites[$storeId] ?? 1);
            $stores[$storeId] = $store;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn(array_values($stores));
        $storeManager->method('getStore')->willReturnCallback(function (int $storeId) use ($stores): Store {
            return $stores[$storeId];
        });

        $factory = $this->createMock(SyncStateFactory::class);
        $factory->method('create')->willReturnCallback(function (): SyncState {
            $state = $this->getMockBuilder(SyncState::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getId'])
                ->getMock();
            $state->method('getId')->willReturn(1);
            $state->setLastVersionId($this->lastVersion);
            return $state;
        });
        $this->syncStateResource->method('loadByStoreId')->willReturnCallback(
            function (SyncState $state, int $storeId) {
                $state->setStoreId($storeId);
                return $this->syncStateResource;
            }
        );

        return new SyncChangedProducts(
            $this->changelogReader,
            $this->notifier,
            $factory,
            $this->syncStateResource,
            $storeManager,
            $this->logger(),
            $this->changeFilter,
            $this->indexState,
            $this->heldProducts,
            $this->resendProducts
        );
    }

    /**
     * @param string $level
     * @return array<int, array{0: string, 1: string, 2: array}>
     */
    private function logsOf(string $level): array
    {
        return array_values(array_filter($this->logs, static function (array $log) use ($level): bool {
            return $log[0] === $level;
        }));
    }

    private function logger(): AbstractLogger
    {
        $logs = &$this->logs;

        return new class ($logs) extends AbstractLogger {
            /** @var array<int, array{0: string, 1: string, 2: array}> */
            private array $logs;

            public function __construct(array &$logs)
            {
                $this->logs = &$logs;
            }

            public function log($level, $message, array $context = []): void
            {
                $this->logs[] = [(string)$level, (string)$message, $context];
            }
        };
    }
}
