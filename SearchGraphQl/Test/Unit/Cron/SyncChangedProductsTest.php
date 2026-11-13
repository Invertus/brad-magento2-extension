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
use BradSearch\SearchGraphQl\Model\Sync\CategoryIndexState;
use BradSearch\SearchGraphQl\Model\Sync\ChangeFilter;
use BradSearch\SearchGraphQl\Model\Sync\ChangelogReader;
use BradSearch\SearchGraphQl\Model\Sync\HeldProducts;
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

    /** @var CategoryIndexState&MockObject */
    private $categoryIndexState;

    /** @var HeldProducts&MockObject */
    private $heldProducts;

    /** @var array<int, int> store id => saved cursor */
    private array $savedVersions = [];

    /** @var int[] What the changelog returns for versions 400..500 */
    private array $changelogIds = [10, 11, 12];

    /** @var array<int, array{0: int[], 1: int[]}> store id => [released, held] */
    private array $heldUpdates = [];

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

        $this->categoryIndexState = $this->createMock(CategoryIndexState::class);
        $this->heldProducts = $this->createMock(HeldProducts::class);
        $this->heldProducts->method('isAvailable')->willReturn(true);
        $this->heldProducts->method('heldLongerThan')->willReturn(['count' => 0, 'held_since' => null, 'sample' => []]);
        $this->heldProducts->method('update')->willReturnCallback(function (int $storeId, array $release, array $hold): void {
            $this->heldUpdates[$storeId] = [$release, $hold];
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

    public function testABrokenFilterSendsEveryChangelogProductAndStoresNoHash(): void
    {
        $this->changeFilter->method('filter')->willThrowException(new \RuntimeException('table missing'));
        $this->notifier->expects($this->once())->method('notify')->with([10, 11, 12], 1, self::CURRENT_VERSION, false)
            ->willReturn(true);
        $this->changeFilter->expects($this->never())->method('store');

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
        $this->categoryIndexState->method('notIndexed')->with([10, 11, 12])->willReturn([11]);
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
        $this->heldProducts->method('forStore')->with(1)->willReturn([20]);
        $this->categoryIndexState->method('notIndexed')->with([10, 11, 12, 20])->willReturn([]);
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [10, 11, 12, 20]])
            ->willReturn([1 => ['changed' => [20], 'hashes' => [20 => 'h20']]]);
        $this->notifier->expects($this->once())->method('notify')->with([20], 1, self::CURRENT_VERSION, true)
            ->willReturn(true);
        $this->changeFilter->expects($this->once())->method('store')->with([20 => 'h20'], 1);

        $this->cron([1])->execute();

        $this->assertSame([1 => [[10, 11, 12, 20], []]], $this->heldUpdates);
    }

    public function testHeldProductsAreCheckedAgainWhenTheChangelogHasNothingNew(): void
    {
        $this->changelogIds = [];
        $this->heldProducts->method('forStore')->willReturn([20]);
        $this->categoryIndexState->method('notIndexed')->willReturn([]);
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [20]])
            ->willReturn([1 => ['changed' => [20], 'hashes' => [20 => 'h20']]]);
        $this->notifier->expects($this->once())->method('notify')->with([20])->willReturn(true);

        $this->cron([1])->execute();

        $this->assertSame([1 => [[20], []]], $this->heldUpdates);
        $this->assertSame([1 => self::CURRENT_VERSION], $this->savedVersions);
    }

    public function testOnlyWaitingProductsSendsNothingAndStillMovesTheCursor(): void
    {
        $this->categoryIndexState->method('notIndexed')->willReturn([10, 11, 12]);
        $this->changeFilter->expects($this->never())->method('filter');
        $this->notifier->expects($this->never())->method('notify');

        $this->cron([1])->execute();

        $this->assertSame([1 => [[], [10, 11, 12]]], $this->heldUpdates);
        $this->assertSame([1 => self::CURRENT_VERSION], $this->savedVersions);
    }

    public function testAFailedNotificationLeavesTheHeldProductsAsTheyWere(): void
    {
        $this->categoryIndexState->method('notIndexed')->willReturn([11]);
        $this->changeFilter->method('filter')->willReturn([1 => ['changed' => [10], 'hashes' => [10 => 'a']]]);
        $this->notifier->method('notify')->willReturn(false);
        $this->heldProducts->expects($this->never())->method('update');

        $this->cron([1])->execute();

        $this->assertSame([], $this->savedVersions);
    }

    public function testABrokenCategoryIndexCheckHoldsNothing(): void
    {
        $this->categoryIndexState->method('notIndexed')->willThrowException(new \RuntimeException('no mview_state'));
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [10, 11, 12]])
            ->willReturn([1 => ['changed' => [10, 11, 12], 'hashes' => [10 => 'a', 11 => 'b', 12 => 'c']]]);
        $this->notifier->expects($this->once())->method('notify')->with([10, 11, 12])->willReturn(true);

        $this->cron([1])->execute();

        $this->assertSame([1 => [[10, 11, 12], []]], $this->heldUpdates);
    }

    public function testWithoutTheHeldTableNothingIsHeldAndEverythingReadyIsSent(): void
    {
        $heldProducts = $this->createMock(HeldProducts::class);
        $heldProducts->method('isAvailable')->willReturn(false);
        $heldProducts->expects($this->never())->method('forStore');
        $heldProducts->expects($this->never())->method('update');
        $this->heldProducts = $heldProducts;
        $this->categoryIndexState->expects($this->never())->method('notIndexed');
        $this->changeFilter->expects($this->once())->method('filter')->with([1 => [10, 11, 12]])
            ->willReturn([1 => ['changed' => [11], 'hashes' => [11 => 'h11']]]);
        $this->notifier->expects($this->once())->method('notify')->with([11])->willReturn(true);

        $this->cron([1])->execute();

        $this->assertSame([1 => self::CURRENT_VERSION], $this->savedVersions);
    }

    public function testAProductHeldTooLongLogsAWarningAndStaysHeld(): void
    {
        $this->categoryIndexState->method('notIndexed')->willReturn([11]);
        $this->changeFilter->method('filter')->willReturn([1 => ['changed' => [], 'hashes' => []]]);
        $heldProducts = $this->createMock(HeldProducts::class);
        $heldProducts->method('isAvailable')->willReturn(true);
        $heldProducts->method('update')->willReturnCallback(function (int $storeId, array $release, array $hold): void {
            $this->heldUpdates[$storeId] = [$release, $hold];
        });
        $heldProducts->expects($this->once())->method('heldLongerThan')->with(1, 15)
            ->willReturn(['count' => 1, 'held_since' => '2026-11-13 09:00:00', 'sample' => [11]]);
        $this->heldProducts = $heldProducts;

        $this->cron([1])->execute();

        $this->assertSame([1 => [[10, 12], [11]]], $this->heldUpdates);
        $warnings = array_values(array_filter($this->logs, static function (array $log): bool {
            return $log[0] === 'warning';
        }));
        $this->assertCount(1, $warnings);
        $this->assertSame([11], $warnings[0][2]['product_ids']);
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
            $stores[] = $store;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);

        $factory = $this->createMock(SyncStateFactory::class);
        $factory->method('create')->willReturnCallback(function (): SyncState {
            $state = $this->getMockBuilder(SyncState::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getId'])
                ->getMock();
            $state->method('getId')->willReturn(1);
            $state->setLastVersionId(400);
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
            $this->categoryIndexState,
            $this->heldProducts
        );
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
