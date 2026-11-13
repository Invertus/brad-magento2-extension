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

    public function testDuringAFullReindexEveryLoggedProductAndEveryHeldOneWaits(): void
    {
        $this->connection->method('fetchRow')->willReturn(['mode' => 'enabled', 'status' => 'suspended', 'version_id' => '900']);
        $this->connection->expects($this->once())->method('fetchCol')->willReturn(['10']);

        $waiting = $this->state()->notIndexed(IndexState::CATEGORIES, [10, 11, 12], [12, 99]);

        sort($waiting);
        $this->assertSame([10, 12], $waiting);
        $this->assertNotContains(['version_id > ?', 900], $this->wheres);
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

        return new IndexState($resource);
    }
}
