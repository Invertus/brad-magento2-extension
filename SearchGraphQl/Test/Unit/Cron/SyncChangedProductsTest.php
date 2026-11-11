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
use BradSearch\SearchGraphQl\Model\Sync\WebhookNotifier;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

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

    /** @var array<int, int> store id => saved cursor */
    private array $savedVersions = [];

    protected function setUp(): void
    {
        $this->changelogReader = $this->createMock(ChangelogReader::class);
        $this->changelogReader->method('isChangelogAvailable')->willReturn(true);
        $this->changelogReader->method('getCurrentVersion')->willReturn(self::CURRENT_VERSION);
        $this->changelogReader->method('getChangedProductIds')->with(400, self::CURRENT_VERSION)->willReturn([10, 11, 12]);

        $this->notifier = $this->createMock(WebhookNotifier::class);
        $this->notifier->method('isSyncEnabled')->willReturn(true);

        $this->syncStateResource = $this->createMock(SyncStateResource::class);
        $this->syncStateResource->method('save')->willReturnCallback(function (SyncState $state) {
            $this->savedVersions[$state->getStoreId()] = $state->getLastVersionId();
            return $this->syncStateResource;
        });

        $this->changeFilter = $this->createMock(ChangeFilter::class);
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
            new NullLogger(),
            $this->changeFilter
        );
    }
}
