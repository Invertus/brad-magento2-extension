<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Model\Sync;

use BradSearch\SearchGraphQl\Model\Sync\IndexState;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Module\Manager as ModuleManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class IndexStateTest extends TestCase
{
    /** @var AdapterInterface&MockObject */
    private $connection;

    /** @var array<int, array{0: string, 1: mixed}> where() calls on every select */
    private array $wheres = [];

    /** @var string[] from() tables */
    private array $froms = [];

    /** @var ModuleManager&MockObject */
    private $modules;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturnCallback(function (): Select {
            $select = $this->createMock(Select::class);
            $select->method('where')->willReturnCallback(function (string $condition, $value = null) use ($select) {
                $this->wheres[] = [$condition, $value];
                return $select;
            });
            $select->method('from')->willReturnCallback(function ($table) use ($select) {
                $this->froms[] = is_array($table) ? (string)reset($table) : (string)$table;
                return $select;
            });
            $select->method($this->logicalNot($this->logicalOr($this->equalTo('where'), $this->equalTo('from'))))
                ->willReturnSelf();
            return $select;
        });
        $this->connection->method('isTableExists')->willReturn(true);
        $this->modules = $this->createMock(ModuleManager::class);
    }

    public function testProductsWithChangelogRowsAfterTheIndexedVersionAreWaiting(): void
    {
        $this->connection->method('fetchRow')->willReturn(['mode' => 'enabled', 'status' => 'idle', 'version_id' => '700']);
        $this->connection->expects($this->once())->method('fetchCol')->willReturn(['11']);

        $this->assertSame([11], $this->state()->notIndexed(IndexState::CATEGORIES, [10, 11, 12]));
        $this->assertContains(['version_id > ?', 700], $this->wheres);
        $this->assertContains(['entity_id IN (?)', [10, 11, 12]], $this->wheres);
        $this->assertContains('catalog_product_category_cl', $this->froms);
    }

    public function testThePriceRuleViewReadsItsOwnChangelog(): void
    {
        $this->connection->method('fetchRow')->willReturn(['mode' => 'enabled', 'status' => 'idle', 'version_id' => '5']);
        $this->connection->method('fetchCol')->willReturn([]);

        $this->state()->notIndexed(IndexState::PRICE_RULES, [10]);

        $this->assertContains('catalogrule_product_cl', $this->froms);
        $this->assertContains(['view_id = ?', 'catalogrule_product'], $this->wheres);
    }

    public function testDuringAFullReindexEveryProductWaits(): void
    {
        // The hourly changelog cleanup may have deleted the rows of changes made before the reindex started.
        $this->connection->method('fetchRow')->willReturn(['mode' => 'enabled', 'status' => 'suspended', 'version_id' => '900']);
        $this->connection->expects($this->never())->method('fetchCol');

        $this->assertSame([10, 11, 12], $this->state()->notIndexed(IndexState::CATEGORIES, [10, 11, 12, 11]));
    }

    public function testAfterAFailedReindexEveryProductWaitsUntilTheRebuild(): void
    {
        // Magento's catch block marks the indexer invalid and resumes the view: idle, version past the rows.
        $this->connection->method('fetchRow')->willReturn(['mode' => 'enabled', 'status' => 'idle', 'version_id' => '900']);
        $this->connection->method('fetchOne')->willReturn('1');
        $this->connection->expects($this->never())->method('fetchCol');

        $this->assertSame([10, 11], $this->state()->notIndexed(IndexState::CATEGORIES, [10, 11]));
        $this->assertContains(['indexer_id IN (?)', ['catalog_category_product', 'catalog_product_category']], $this->wheres);
        $this->assertContains(['status <> ?', 'valid'], $this->wheres);
        $this->assertContains('indexer_state', $this->froms);
    }

    public function testThePriceRuleViewChecksBothRuleIndexers(): void
    {
        $this->connection->method('fetchRow')->willReturn(['mode' => 'enabled', 'status' => 'idle', 'version_id' => '5']);
        $this->connection->method('fetchOne')->willReturn('0');
        $this->connection->method('fetchCol')->willReturn([]);

        $this->state()->notIndexed(IndexState::PRICE_RULES, [10]);

        $this->assertContains(['indexer_id IN (?)', ['catalogrule_rule', 'catalogrule_product']], $this->wheres);
    }

    public function testTheStockViewReadsItsOwnChangelog(): void
    {
        $this->connection->method('fetchRow')->willReturn(['mode' => 'enabled', 'status' => 'idle', 'version_id' => '5']);
        $this->connection->method('fetchOne')->willReturn('0');
        $this->connection->method('fetchCol')->willReturn(['10']);

        $this->assertSame([10], $this->state()->notIndexed(IndexState::STOCK, [10]));
        $this->assertContains('cataloginventory_stock_cl', $this->froms);
        $this->assertContains(['indexer_id IN (?)', ['cataloginventory_stock']], $this->wheres);
    }

    public function testStockWaitsForTheIndexOnlyWithoutTheInventoryModules(): void
    {
        $this->modules->method('isEnabled')->with('Magento_InventoryCatalog')->willReturnOnConsecutiveCalls(true, false);

        $this->assertFalse($this->state()->stockWaitsForIndex());
        $this->assertTrue($this->state()->stockWaitsForIndex());
    }

    public function testUpdateOnSaveHoldsNothing(): void
    {
        $this->connection->method('fetchRow')->willReturn(['mode' => 'disabled', 'status' => 'idle', 'version_id' => '0']);
        $this->connection->expects($this->never())->method('fetchCol');

        $this->assertSame([], $this->state()->notIndexed(IndexState::CATEGORIES, [10]));
    }

    public function testAMissingViewHoldsNothing(): void
    {
        $this->connection->method('fetchRow')->willReturn(false);
        $this->connection->expects($this->never())->method('fetchCol');

        $this->assertSame([], $this->state()->notIndexed(IndexState::CATEGORIES, [10]));
    }

    public function testNoProductsReadsNothing(): void
    {
        $this->connection->expects($this->never())->method('fetchRow');

        $this->assertSame([], $this->state()->notIndexed(IndexState::CATEGORIES, []));
    }

    public function testLargeListsAreReadInBatches(): void
    {
        $this->connection->method('fetchRow')->willReturn(['mode' => 'enabled', 'status' => 'idle', 'version_id' => '1']);
        $this->connection->expects($this->exactly(3))->method('fetchCol')->willReturn([]);

        $this->assertSame([], $this->state()->notIndexed(IndexState::CATEGORIES, range(1, 2500)));
    }

    public function testWebsitesWithActivePriceRules(): void
    {
        $this->connection->method('fetchCol')->willReturn(['9', '3']);

        $this->assertSame([9, 3], $this->state()->websitesWithPriceRules());
        $this->assertContains(['r.is_active = 1', null], $this->wheres);
    }

    private function state(): IndexState
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new IndexState($resource, $this->modules);
    }
}
