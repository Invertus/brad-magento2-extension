<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Model\Sync;

use BradSearch\SearchGraphQl\Model\Sync\StockStatusSnapshot;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class StockStatusSnapshotTest extends TestCase
{
    /** @var AdapterInterface&MockObject */
    private $connection;

    /** @var array<int, array{0: string, 1: mixed}> where() calls */
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
    }

    public function testAnEmptyCopyIsFilledAndReportsNoChange(): void
    {
        // Every product would differ, and the stored hashes already describe what BradSearch has.
        $this->connection->method('fetchOne')->willReturn(false);
        $this->connection->expects($this->once())->method('insertFromSelect')
            ->with($this->anything(), 'bradsearch_stock_status', ['product_id', 'website_id', 'stock_status'],
                AdapterInterface::INSERT_IGNORE)
            ->willReturn('INSERT');
        $this->connection->expects($this->once())->method('query')->with('INSERT');
        $this->connection->expects($this->never())->method('fetchAll');

        $this->assertSame([], $this->snapshot()->changes());
        $this->assertContains(['stock_id = ?', 1], $this->wheres);
    }

    public function testChangedAndNewRowsAreReturnedWithTheValuesRead(): void
    {
        $this->connection->method('fetchOne')->willReturn('5');
        $this->connection->expects($this->never())->method('insertFromSelect');
        $this->connection->method('fetchAll')->willReturn([
            ['product_id' => '72249', 'website_id' => '0', 'stock_status' => '1'],
        ]);

        $this->assertSame(
            [['product_id' => 72249, 'website_id' => 0, 'stock_status' => 1]],
            $this->snapshot()->changes()
        );
        $this->assertContains(['s.stock_id = ?', 1], $this->wheres);
        $this->assertContains(['n.product_id IS NULL OR n.stock_status <> s.stock_status', null], $this->wheres);
    }

    public function testSaveWritesTheValuesItIsGivenInBatches(): void
    {
        $rows = [];
        for ($id = 1; $id <= 1500; $id++) {
            $rows[] = ['product_id' => $id, 'website_id' => 0, 'stock_status' => 1];
        }
        $this->connection->expects($this->exactly(2))->method('insertOnDuplicate')
            ->with('bradsearch_stock_status', $this->anything(), ['stock_status']);

        $this->snapshot()->save($rows);
    }

    public function testChangedAmongNamedProducts(): void
    {
        $this->connection->method('fetchCol')->willReturn(['12']);

        $this->assertSame([12], $this->snapshot()->changedAmong([11, 12, 11]));
        $this->assertContains(['s.product_id IN (?)', [11, 12]], $this->wheres);
        $this->assertContains(['n.stock_status <> s.stock_status', null], $this->wheres);
    }

    public function testChangedAmongAnIdRange(): void
    {
        $this->connection->method('fetchCol')->willReturn(['50']);

        $this->assertSame([50], $this->snapshot()->changedAmong(null, 4, 90));
        $this->assertContains(['s.product_id >= ?', 4], $this->wheres);
        $this->assertContains(['s.product_id <= ?', 90], $this->wheres);
    }

    private function snapshot(): StockStatusSnapshot
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new StockStatusSnapshot($resource);
    }
}
