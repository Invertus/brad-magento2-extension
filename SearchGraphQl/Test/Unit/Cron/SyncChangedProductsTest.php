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
use BradSearch\SearchGraphQl\Model\Sync\ProductRelations;
use BradSearch\SearchGraphQl\Model\Sync\ResendProducts;
use BradSearch\SearchGraphQl\Model\Sync\StockStatusSnapshot;
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

    /** @var ChangelogReader&MockObject */
    private $stockChangelogReader;

    /** @var int|null The stock cursor every store starts the run with; null before its first read */
    private ?int $lastStockVersion = 80;

    /** @var int[] What the stock changelog returns for versions 80..90 */
    private array $stockIds = [];

    /** @var array<int, int> store id => saved stock cursor */
    private array $savedStockVersions = [];

    private bool $stockCursorColumn = true;

    private bool $stateExists = true;

    /** @var array<int, int[]> child => parents */
    private array $parents = [];

    /** @var array<int, int[]> parent => children */
    private array $children = [];

    /** @var ProductRelations&MockObject */
    private $relations;

    /** @var StockStatusSnapshot&MockObject */
    private $snapshot;

    /** @var array<int, array{product_id: int, website_id: int, stock_status: int}> */
    private array $statusChanges = [];

    /** @var string[] Calls on the held products and the snapshot, in order */
    private array $calls = [];

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
            if ($state->getLastStockVersionId() !== null) {
                $this->savedStockVersions[$state->getStoreId()] = $state->getLastStockVersionId();
            }
            return $this->syncStateResource;
        });
        $this->syncStateResource->method('hasStockCursor')->willReturnCallback(function (): bool {
            return $this->stockCursorColumn;
        });

        $this->stockChangelogReader = $this->createMock(ChangelogReader::class);
        $this->stockChangelogReader->method('isChangelogAvailable')->willReturn(true);
        $this->stockChangelogReader->method('getCurrentVersion')->willReturn(90);
        $this->stockChangelogReader->method('getChangedProductIds')->with(80, 90)
            ->willReturnCallback(function (): array {
                return $this->stockIds;
            });

        $this->snapshot = $this->createMock(StockStatusSnapshot::class);
        $this->snapshot->method('isAvailable')->willReturn(true);
        $this->snapshot->method('changes')->willReturnCallback(function (): array {
            return $this->statusChanges;
        });
        $this->snapshot->method('save')->willReturnCallback(function (array $rows): void {
            $this->calls[] = 'save ' . implode(',', array_column($rows, 'product_id'));
        });

        $this->relations = $this->createMock(ProductRelations::class);
        $this->relations->method('parentsOf')->willReturnCallback(function (array $ids): array {
            return array_intersect_key($this->parents, array_flip($ids));
        });
        $this->relations->method('childrenOf')->willReturnCallback(function (array $ids): array {
            return array_intersect_key($this->children, array_flip($ids));
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

    public function testWithoutTheStockStatusTableAStockChangeWaitsForTheStockIndex(): void
    {
        // Without the status compare nothing would see a status written after the stock item's row.
        $this->snapshotMissing();
        $this->notIndexed[IndexState::STOCK] = [11];
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [10, 12]])
            ->willReturn([1 => ['changed' => [], 'hashes' => []]]);

        $this->cron([1])->execute();

        $this->assertSame([1 => [[10, 12], [11]]], $this->heldUpdates);
        $this->assertSame([IndexState::CATEGORIES, IndexState::STOCK], array_column($this->indexChecks, 0));
    }

    public function testWithoutActivePriceRulesTheRuleIndexIsNotRead(): void
    {
        $this->changeFilter->method('filter')->willReturn([1 => ['changed' => [], 'hashes' => []]]);

        $this->cron([1])->execute();

        $this->assertSame([IndexState::CATEGORIES], array_column($this->indexChecks, 0));
    }

    public function testWithTheStockStatusCompareNothingWaitsForTheStockIndex(): void
    {
        // The compare finds the stock index's own writes, so waiting for it would only delay the flip.
        $this->notIndexed[IndexState::STOCK] = [11];
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [10, 11, 12]])
            ->willReturn([1 => ['changed' => [], 'hashes' => []]]);

        $this->cron([1])->execute();

        $this->assertNotContains(IndexState::STOCK, array_column($this->indexChecks, 0));
        $this->assertSame([1 => [[10, 11, 12], []]], $this->heldUpdates);
    }

    public function testAFailedStockStatusCompareFallsBackToWaitingForTheStockIndex(): void
    {
        $this->snapshot = $this->createMock(StockStatusSnapshot::class);
        $this->snapshot->method('isAvailable')->willReturn(true);
        $this->snapshot->method('changes')->willThrowException(new \RuntimeException('db gone'));
        $this->notIndexed[IndexState::STOCK] = [11];
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [10, 12]])
            ->willReturn([1 => ['changed' => [], 'hashes' => []]]);

        $this->cron([1])->execute();

        $this->assertSame([1 => [[10, 12], [11]]], $this->heldUpdates);
    }

    public function testAStatusChangeOnAProductSentAtTheLimitIsSentAgain(): void
    {
        // Product 20 waits for the category index and was sent at the hold limit; its status then changed
        // with no changelog row (an order, or an import writing only the status).
        $this->lastVersion = self::CURRENT_VERSION;
        $this->held[1] = [20 => true];
        $this->notIndexed[IndexState::CATEGORIES] = [20];
        $this->statusChanges = [['product_id' => 20, 'website_id' => 0, 'stock_status' => 0]];
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [20]], [1 => []])
            ->willReturn([1 => ['changed' => [20], 'hashes' => [20 => 'h20']]]);
        $this->notifier->expects($this->once())->method('notify')->with([20], 1, self::CURRENT_VERSION, true)
            ->willReturn(true);

        $this->cron([1])->execute();

        $this->assertSame([20 => true], $this->held[1], 'still held until the category index has it');
    }

    public function testAProductSentAtTheLimitWithNoNewChangeIsNotSentAgain(): void
    {
        $this->lastVersion = self::CURRENT_VERSION;
        $this->held[1] = [20 => true];
        $this->notIndexed[IndexState::CATEGORIES] = [20];
        $this->changeFilter->expects($this->never())->method('filter');
        $this->notifier->expects($this->never())->method('notify');

        $this->cron([1])->execute();
    }

    public function testStockChangelogProductsAreCheckedWithTheOthersAndBothCursorsMove(): void
    {
        $this->stockIds = [20, 11];
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [10, 11, 12, 20]])
            ->willReturn([1 => ['changed' => [20], 'hashes' => [20 => 'h20']]]);
        $this->notifier->expects($this->once())->method('notify')->with([20], 1, self::CURRENT_VERSION, true)
            ->willReturn(true);

        $this->cron([1])->execute();

        $this->assertSame([1 => self::CURRENT_VERSION], $this->savedVersions);
        $this->assertSame([1 => 90], $this->savedStockVersions);
    }

    public function testOnlyTheStockChangelogHasNewRows(): void
    {
        $this->lastVersion = self::CURRENT_VERSION;
        $this->stockIds = [20];
        $this->changelogReader->expects($this->never())->method('getChangedProductIds');
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [20]])
            ->willReturn([1 => ['changed' => [20], 'hashes' => [20 => 'h20']]]);
        $this->notifier->method('notify')->willReturn(true);

        $this->cron([1])->execute();

        $this->assertSame([1 => 90], $this->savedStockVersions);
    }

    public function testAnEmptySearchChangelogDoesNotStopTheStockChanges(): void
    {
        $this->changelogReader = $this->createMock(ChangelogReader::class);
        $this->changelogReader->method('isChangelogAvailable')->willReturn(true);
        $this->changelogReader->method('getCurrentVersion')->willReturn(0);
        $this->lastVersion = 0;
        $this->stockIds = [20];
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [20]])
            ->willReturn([1 => ['changed' => [20], 'hashes' => [20 => 'h20']]]);
        $this->notifier->method('notify')->willReturn(true);

        $this->cron([1])->execute();

        $this->assertSame([1 => 90], $this->savedStockVersions);
    }

    public function testTheFirstRunThatReadsTheStockChangelogStartsAtItsEnd(): void
    {
        $this->lastStockVersion = null;
        $this->stockIds = [20];
        $this->stockChangelogReader->expects($this->never())->method('getChangedProductIds');
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [10, 11, 12]])
            ->willReturn([1 => ['changed' => [], 'hashes' => []]]);

        $this->cron([1])->execute();

        $this->assertSame([1 => 90], $this->savedStockVersions);
    }

    public function testWithoutTheStockCursorColumnTheSearchChangelogStillSyncs(): void
    {
        $this->stockCursorColumn = false;
        $this->lastStockVersion = null;
        $this->stockChangelogReader->expects($this->never())->method('getChangedProductIds');
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [10, 11, 12]])
            ->willReturn([1 => ['changed' => [], 'hashes' => []]]);

        $this->cron([1])->execute();

        $this->assertSame([1 => self::CURRENT_VERSION], $this->savedVersions);
        $this->assertSame([], $this->savedStockVersions);
        $this->assertCount(1, array_filter($this->logsOf('error'), static function (array $log): bool {
            return strpos($log[1], 'run setup:upgrade') !== false;
        }));
    }

    public function testWithoutAStockChangelogTheSearchChangelogStillSyncs(): void
    {
        // A stock index on "Update on Save" has no changelog.
        $this->stockChangelogReader = $this->createMock(ChangelogReader::class);
        $this->stockChangelogReader->method('isChangelogAvailable')->willReturn(false);
        $this->stockChangelogReader->expects($this->never())->method('getChangedProductIds');
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [10, 11, 12]])
            ->willReturn([1 => ['changed' => [], 'hashes' => []]]);

        $this->cron([1])->execute();

        $this->assertSame([1 => self::CURRENT_VERSION], $this->savedVersions);
        $this->assertSame([], $this->logsOf('error'));
    }

    public function testAStockStatusChangeWithNoChangelogRowIsHeldThenCheckedThroughTheFilter(): void
    {
        // A stock import that writes the status while the stock item stays the same leaves no row.
        $this->lastVersion = self::CURRENT_VERSION;
        $this->statusChanges = [['product_id' => 20, 'website_id' => 0, 'stock_status' => 1]];
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [20]], [1 => []])
            ->willReturn([1 => ['changed' => [20], 'hashes' => [20 => 'h20']]]);
        $this->notifier->expects($this->once())->method('notify')->with([20], 1, self::CURRENT_VERSION, true)
            ->willReturn(true);

        $this->cron([1])->execute();

        $this->assertSame(['held 1 /20', 'save 20', 'held 1 20/'], $this->calls);
        $this->assertSame([], $this->held[1]);
    }

    public function testAProductWhoseStatusRowIsGoneIsHeldAndChecked(): void
    {
        $this->lastVersion = self::CURRENT_VERSION;
        $this->statusChanges = [['product_id' => 20, 'website_id' => 0, 'stock_status' => null]];
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [20]], [1 => []])
            ->willReturn([1 => ['changed' => [20], 'hashes' => [20 => 'h20']]]);
        $this->notifier->method('notify')->willReturn(true);

        $this->cron([1])->execute();

        $this->assertSame(['held 1 /20', 'save 20', 'held 1 20/'], $this->calls);
    }

    public function testEveryStoreHoldsTheStatusChangesBeforeTheSnapshotIsSaved(): void
    {
        $this->lastVersion = self::CURRENT_VERSION;
        $this->statusChanges = [
            ['product_id' => 20, 'website_id' => 0, 'stock_status' => 0],
            ['product_id' => 21, 'website_id' => 0, 'stock_status' => 1],
        ];
        $this->changeFilter->method('filter')->willReturn([
            1 => ['changed' => [], 'hashes' => []],
            3 => ['changed' => [], 'hashes' => []],
        ]);

        $this->cron([1, 3])->execute();

        $this->assertSame(['held 1 /20,21', 'held 3 /20,21', 'save 20,21'], array_slice($this->calls, 0, 3));
    }

    public function testAFailedNotificationKeepsTheStatusChangeHeld(): void
    {
        $this->lastVersion = self::CURRENT_VERSION;
        $this->statusChanges = [['product_id' => 20, 'website_id' => 0, 'stock_status' => 0]];
        $this->changeFilter->method('filter')->willReturn([1 => ['changed' => [20], 'hashes' => [20 => 'h20']]]);
        $this->notifier->method('notify')->willReturn(false);

        $this->cron([1])->execute();

        $this->assertSame([20 => false], $this->held[1]);
    }

    public function testWithoutTheStockStatusTableTheSyncWorksAsBefore(): void
    {
        $this->snapshot = $this->createMock(StockStatusSnapshot::class);
        $this->snapshot->method('isAvailable')->willReturn(false);
        $this->snapshot->expects($this->never())->method('changes');
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [10, 11, 12]])
            ->willReturn([1 => ['changed' => [], 'hashes' => []]]);

        $this->cron([1])->execute();

        $this->assertCount(1, array_filter($this->logsOf('error'), static function (array $log): bool {
            return strpos($log[1], 'Stock status table missing') !== false;
        }));
    }

    public function testWithoutTheHeldTableTheStockStatusIsNotChecked(): void
    {
        // A status change is carried by the held table until it is sent; without it, it would be lost.
        $this->heldProducts = $this->heldProductsMock(false);
        $this->snapshot->expects($this->never())->method('changes');
        $this->changeFilter->method('filter')->willReturn([1 => ['changed' => [], 'hashes' => []]]);

        $this->cron([1])->execute();
    }

    public function testABrokenStockStatusCheckDoesNotStopTheSync(): void
    {
        $this->snapshot = $this->createMock(StockStatusSnapshot::class);
        $this->snapshot->method('isAvailable')->willReturn(true);
        $this->snapshot->method('changes')->willThrowException(new \RuntimeException('db gone'));
        $this->snapshot->expects($this->never())->method('save');
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [10, 11, 12]])
            ->willReturn([1 => ['changed' => [], 'hashes' => []]]);

        $this->cron([1])->execute();
    }

    public function testHeldProductsAreHandledWhileTheChangelogsAreEmpty(): void
    {
        // Magento's hourly cleanup can empty a changelog: its max version reads 0.
        $this->changelogReader = $this->createMock(ChangelogReader::class);
        $this->changelogReader->method('isChangelogAvailable')->willReturn(true);
        $this->changelogReader->method('getCurrentVersion')->willReturn(0);
        $this->stockChangelogReader = $this->createMock(ChangelogReader::class);
        $this->stockChangelogReader->method('isChangelogAvailable')->willReturn(true);
        $this->stockChangelogReader->method('getCurrentVersion')->willReturn(0);
        $this->statusChanges = [['product_id' => 20, 'website_id' => 0, 'stock_status' => 0]];
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [20]])
            ->willReturn([1 => ['changed' => [20], 'hashes' => [20 => 'h20']]]);
        $this->notifier->method('notify')->willReturn(true);

        $this->cron([1])->execute();

        $this->assertSame([1 => 400], $this->savedVersions, 'the cursor does not move back');
    }

    public function testANewStoreDoesNotStartWhileTheChangelogReadsZero(): void
    {
        // 0 is an empty changelog or a failed read; a cursor started there would replay the whole backlog.
        $this->stateExists = false;
        $this->changelogReader = $this->createMock(ChangelogReader::class);
        $this->changelogReader->method('isChangelogAvailable')->willReturn(true);
        $this->changelogReader->method('getCurrentVersion')->willReturn(0);
        $this->changeFilter->expects($this->never())->method('filter');

        $this->cron([1])->execute();

        $this->assertSame([], $this->savedVersions);
    }

    public function testAnEmptyStockChangelogDoesNotStartTheStockCursor(): void
    {
        $this->lastStockVersion = null;
        $this->stockChangelogReader = $this->createMock(ChangelogReader::class);
        $this->stockChangelogReader->method('isChangelogAvailable')->willReturn(true);
        $this->stockChangelogReader->method('getCurrentVersion')->willReturn(0);
        $this->changeFilter->method('filter')->willReturn([1 => ['changed' => [], 'hashes' => []]]);

        $this->cron([1])->execute();

        $this->assertSame([], $this->savedStockVersions);
        $this->assertSame([1 => self::CURRENT_VERSION], $this->savedVersions);
    }

    public function testTheParentsOfChangedProductsAreCheckedToo(): void
    {
        // The stock index sets a bundle out of stock when it indexes its part, with no row for the bundle.
        $this->parents = [11 => [72], 12 => [72, 73]];
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [10, 11, 12, 72, 73]])
            ->willReturn([1 => ['changed' => [72], 'hashes' => [72 => 'h72']]]);
        $this->notifier->expects($this->once())->method('notify')->with([72], 1, self::CURRENT_VERSION, true)
            ->willReturn(true);

        $this->cron([1])->execute();
    }

    public function testWithoutTheStockStatusTableAParentWaitsWhileAChildIsNotStockIndexed(): void
    {
        $this->snapshotMissing();
        $this->parents = [90 => [72]];
        $this->changelogIds = [90];
        $this->children = [72 => [90, 91]];
        $this->notIndexed[IndexState::STOCK] = [90];
        $this->changeFilter->expects($this->never())->method('filter');

        $this->cron([1])->execute();

        $this->assertSame([1 => [[], [90, 72]]], $this->heldUpdates);
        $this->assertContains([IndexState::STOCK, [90, 72, 91]], $this->indexChecks);
    }

    public function testABrokenParentCheckSendsTheChangedProducts(): void
    {
        $this->relations = $this->createMock(ProductRelations::class);
        $this->relations->method('parentsOf')->willThrowException(new \RuntimeException('db gone'));
        $this->relations->method('childrenOf')->willReturn([]);
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [10, 11, 12]])
            ->willReturn([1 => ['changed' => [], 'hashes' => []]]);

        $this->cron([1])->execute();

        $this->assertSame([1 => self::CURRENT_VERSION], $this->savedVersions);
    }

    private function snapshotMissing(): void
    {
        $this->snapshot = $this->createMock(StockStatusSnapshot::class);
        $this->snapshot->method('isAvailable')->willReturn(false);
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
                $this->calls[] = 'held ' . $storeId . ' ' . implode(',', $release) . '/' . implode(',', $hold);
                $this->heldUpdates[$storeId] = [$release, $hold];
                foreach ($release as $id) {
                    unset($this->held[$storeId][$id]);
                }
                foreach ($hold as $id) {
                    $this->held[$storeId][$id] = $this->held[$storeId][$id] ?? false;
                }
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
            $state->method('getId')->willReturn($this->stateExists ? 1 : null);
            $state->setLastVersionId($this->lastVersion);
            if ($this->lastStockVersion !== null) {
                $state->setLastStockVersionId($this->lastStockVersion);
            }
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
            $this->resendProducts,
            $this->stockChangelogReader,
            $this->relations,
            $this->snapshot
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
