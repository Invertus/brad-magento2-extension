<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Plugin\InventoryIndexer;

use BradSearch\SearchGraphQl\Model\Sync\ProductRelations;
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

    /** @var ProductRelations&MockObject */
    private $relations;

    /** @var array<int, array{0: int, 1: int[]}> mark() calls */
    private array $marks = [];

    /** @var array<int, bool> store id => live sync on */
    private array $syncEnabled = [1 => true, 3 => true, 5 => false];

    /** @var array<int, array{0: string, 1: mixed}> where() calls */
    private array $wheres = [];

    /** @var array<string, int> sku => product id */
    private array $skus = ['sku-7' => 7, 'sku-8' => 8, '90749' => 90749];

    /** @var array<int, int[]> product id => website ids */
    private array $websites = [7 => [1, 3], 8 => [1], 90749 => [1], 72249 => [1, 3]];

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturnCallback(function (): Select {
            $select = $this->createMock(Select::class);
            $select->method('where')->willReturnCallback(function (string $condition, $value = null) use ($select) {
                $this->wheres[] = [$condition, $value];
                return $select;
            });
            $select->method('from')->willReturnSelf();
            return $select;
        });
        $this->connection->method('fetchCol')->willReturnCallback(function (): array {
            [, $skus] = end($this->wheres);
            return array_values(array_intersect_key($this->skus, array_flip($skus)));
        });
        $this->connection->method('fetchAll')->willReturnCallback(function (): array {
            [, $ids] = end($this->wheres);
            $rows = [];
            foreach ($ids as $id) {
                foreach ($this->websites[$id] ?? [] as $websiteId) {
                    $rows[] = ['product_id' => (string)$id, 'website_id' => (string)$websiteId];
                }
            }
            return $rows;
        });

        $this->resendProducts = $this->createMock(ResendProducts::class);
        $this->resendProducts->method('isAvailable')->willReturn(true);
        $this->resendProducts->method('mark')->willReturnCallback(function (int $storeId, array $ids): void {
            $this->marks[] = [$storeId, $ids];
        });

        $this->notifier = $this->createMock(WebhookNotifier::class);
        $this->notifier->method('isSyncEnabled')->willReturnCallback(function (int $storeId): bool {
            return $this->syncEnabled[$storeId] ?? false;
        });

        $this->relations = $this->createMock(ProductRelations::class);
        $this->relations->method('parentsOf')->willReturn([]);
    }

    public function testProductsWhoseSalabilityChangedAreMarkedInTheirWebsitesLiveSyncStores(): void
    {
        $result = ['sku-7' => false, 'sku-8' => true];

        $this->assertSame($result, $this->plugin()->afterExecute(new \stdClass(), $result));

        $this->assertSame([[1, [7, 8]], [3, [7]]], $this->marks);
    }

    public function testANumericSkuIsFoundAndItsParentsAreMarkedToo(): void
    {
        // PHP keeps a numeric sku as an int key. The consumer's own result renumbers those keys when it
        // adds the parents, which is why the plugin sits on the step before and adds the parents itself.
        $this->relations = $this->createMock(ProductRelations::class);
        $this->relations->expects($this->once())->method('parentsOf')->with([90749])->willReturn([90749 => [72249]]);
        $result = [90749 => false];

        $this->assertSame($result, $this->plugin()->afterExecute(new \stdClass(), $result));

        $this->assertContains(['sku IN (?)', ['90749']], $this->wheres);
        $this->assertSame([[1, [90749, 72249]], [3, [72249]]], $this->marks);
    }

    public function testAnUnknownSkuMarksNothing(): void
    {
        $this->assertSame(['gone' => false], $this->plugin()->afterExecute(new \stdClass(), ['gone' => false]));
        $this->assertSame([], $this->marks);
    }

    public function testNoChangeMarksNothingAndReadsNothing(): void
    {
        $this->connection->expects($this->never())->method('fetchCol');

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
            $this->relations,
            new NullLogger()
        );
    }
}
