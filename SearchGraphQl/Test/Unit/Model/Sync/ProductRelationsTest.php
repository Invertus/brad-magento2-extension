<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Model\Sync;

use BradSearch\SearchGraphQl\Model\Sync\ProductRelations;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ProductRelationsTest extends TestCase
{
    /** @var AdapterInterface&MockObject */
    private $connection;

    /** @var array<int, array{0: string, 1: mixed}> */
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
            $select->method('from')->willReturnSelf();
            return $select;
        });
    }

    public function testParentsByChild(): void
    {
        $this->connection->method('fetchAll')->willReturn([
            ['child_id' => '90', 'parent_id' => '72'],
            ['child_id' => '90', 'parent_id' => '73'],
            ['child_id' => '91', 'parent_id' => '72'],
        ]);

        $this->assertSame([90 => [72, 73], 91 => [72]], $this->relations()->parentsOf([90, 91, 90]));
        $this->assertSame([['child_id IN (?)', [90, 91]]], $this->wheres);
    }

    public function testChildrenByParentInBatches(): void
    {
        $this->connection->expects($this->exactly(2))->method('fetchAll')->willReturn([]);

        $this->assertSame([], $this->relations()->childrenOf(range(1, 1500)));
        $this->assertSame('parent_id IN (?)', $this->wheres[0][0]);
    }

    public function testNoIdsReadsNothing(): void
    {
        $this->connection->expects($this->never())->method('fetchAll');

        $this->assertSame([], $this->relations()->parentsOf([]));
    }

    public function testChildrenOfTheParentsInAnIdRange(): void
    {
        $this->connection->method('fetchAll')->willReturn([['parent_id' => '50', 'child_id' => '200']]);

        $this->assertSame([50 => [200]], $this->relations()->childrenInRange(4, 90));
        $this->assertSame([['parent_id >= ?', 4], ['parent_id <= ?', 90]], $this->wheres);
    }

    private function relations(): ProductRelations
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new ProductRelations($resource);
    }
}
