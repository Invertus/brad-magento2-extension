<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Plugin\InventoryIndexer;

use BradSearch\SearchGraphQl\Model\Sync\ResendProducts;
use BradSearch\SearchGraphQl\Model\Sync\WebhookNotifier;
use BradSearch\SearchGraphQl\Plugin\InventoryIndexer\MarkSalabilityChanges;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class MarkSalabilityChangesTest extends TestCase
{
    /** @var AdapterInterface&MockObject */
    private $connection;

    /** @var ResendProducts&MockObject */
    private $resendProducts;

    /** @var WebhookNotifier&MockObject */
    private $notifier;

    /** @var array<int, array{0: int, 1: int[]}> mark() calls */
    private array $marks = [];

    /** @var array<int, bool> store id => live sync on */
    private array $syncEnabled = [1 => true, 3 => true, 5 => false];

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $select = $this->createMock(Select::class);
        $select->method($this->anything())->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        // Product 7 is on websites 1 and 3, product 8 on website 1 only.
        $this->connection->method('fetchAll')->willReturn([
            ['entity_id' => '7', 'website_id' => '1'],
            ['entity_id' => '7', 'website_id' => '3'],
            ['entity_id' => '8', 'website_id' => '1'],
        ]);

        $this->resendProducts = $this->createMock(ResendProducts::class);
        $this->resendProducts->method('isAvailable')->willReturn(true);
        $this->resendProducts->method('mark')->willReturnCallback(function (int $storeId, array $ids): void {
            $this->marks[] = [$storeId, $ids];
        });

        $this->notifier = $this->createMock(WebhookNotifier::class);
        $this->notifier->method('isSyncEnabled')->willReturnCallback(function (int $storeId): bool {
            return $this->syncEnabled[$storeId] ?? false;
        });
    }

    public function testProductsWhoseSalabilityChangedAreMarkedInTheirWebsitesLiveSyncStores(): void
    {
        $result = ['sku-7' => false, 'sku-8' => true];

        $this->assertSame($result, $this->plugin()->afterExecute(new \stdClass(), $result));

        $this->assertSame([[1, [7, 8]], [3, [7]]], $this->marks);
    }

    public function testNoChangeMarksNothingAndReadsNothing(): void
    {
        $this->connection->expects($this->never())->method('fetchAll');

        $this->assertSame([], $this->plugin()->afterExecute(new \stdClass(), []));
        $this->assertSame([], $this->marks);
    }

    public function testWithoutTheResendTableNothingIsMarked(): void
    {
        $this->resendProducts = $this->createMock(ResendProducts::class);
        $this->resendProducts->method('isAvailable')->willReturn(false);
        $this->resendProducts->expects($this->never())->method('mark');

        $this->assertSame(['sku-7' => false], $this->plugin()->afterExecute(new \stdClass(), ['sku-7' => false]));
    }

    public function testAFailureNeverBreaksTheStockUpdate(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willThrowException(new \RuntimeException('db gone'));

        $this->assertSame(['sku-7' => false], $this->plugin()->afterExecute(new \stdClass(), ['sku-7' => false]));
        $this->assertSame([], $this->marks);
    }

    private function plugin(): MarkSalabilityChanges
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        $stores = [];
        foreach ([1 => 1, 3 => 3, 5 => 1] as $storeId => $websiteId) {
            $store = $this->createMock(Store::class);
            $store->method('getId')->willReturn($storeId);
            $store->method('getWebsiteId')->willReturn($websiteId);
            $stores[] = $store;
        }
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStores')->willReturn($stores);

        return new MarkSalabilityChanges(
            $resource,
            $this->resendProducts,
            $this->notifier,
            $storeManager,
            new NullLogger()
        );
    }
}
