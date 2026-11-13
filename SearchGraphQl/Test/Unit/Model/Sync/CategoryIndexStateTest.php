<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Model\Sync;

use BradSearch\SearchGraphQl\Model\Sync\CategoryIndexState;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class CategoryIndexStateTest extends TestCase
{
    /** @var AdapterInterface&MockObject */
    private $connection;

    /** @var array<int, array{0: string, 1: mixed}> where() calls on the changelog select */
    private array $wheres = [];

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $this->connection->method('select')->willReturnCallback(function (): Select {
            $select = $this->createMock(Select::class);
            $select->method('where')->willReturnCallback(function (string $condition, $value = null) use ($select) {
                $this->wheres[] = [$condition, $value];
                return $select;
            });
            $select->method($this->logicalNot($this->equalTo('where')))->willReturnSelf();
            return $select;
        });
        $this->connection->method('isTableExists')->willReturn(true);
    }

    public function testProductsWithChangelogRowsAfterTheIndexedVersionAreWaiting(): void
    {
        $this->connection->method('fetchRow')->willReturn(['mode' => 'enabled', 'version_id' => '700']);
        $this->connection->expects($this->once())->method('fetchCol')->willReturn(['11']);

        $this->assertSame([11], $this->state()->notIndexed([10, 11, 12]));
        $this->assertContains(['version_id > ?', 700], $this->wheres);
        $this->assertContains(['entity_id IN (?)', [10, 11, 12]], $this->wheres);
    }

    public function testUpdateOnSaveHoldsNothing(): void
    {
        $this->connection->method('fetchRow')->willReturn(['mode' => 'disabled', 'version_id' => '0']);
        $this->connection->expects($this->never())->method('fetchCol');

        $this->assertSame([], $this->state()->notIndexed([10]));
    }

    public function testAMissingViewHoldsNothing(): void
    {
        $this->connection->method('fetchRow')->willReturn(false);
        $this->connection->expects($this->never())->method('fetchCol');

        $this->assertSame([], $this->state()->notIndexed([10]));
    }

    public function testNoProductsReadsNothing(): void
    {
        $this->connection->expects($this->never())->method('fetchRow');

        $this->assertSame([], $this->state()->notIndexed([]));
    }

    public function testLargeListsAreReadInBatches(): void
    {
        $this->connection->method('fetchRow')->willReturn(['mode' => 'enabled', 'version_id' => '1']);
        $this->connection->expects($this->exactly(3))->method('fetchCol')->willReturn([]);

        $this->assertSame([], $this->state()->notIndexed(range(1, 2500)));
    }

    private function state(): CategoryIndexState
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new CategoryIndexState($resource);
    }
}
