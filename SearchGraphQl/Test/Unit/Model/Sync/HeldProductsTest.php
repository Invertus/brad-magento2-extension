<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Model\Sync;

use BradSearch\SearchGraphQl\Model\Sync\HeldProducts;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class HeldProductsTest extends TestCase
{
    /** @var AdapterInterface&MockObject */
    private $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
    }

    public function testUpdateReleasesSentProductsAndHoldsWaitingOnesKeepingTheirFirstHeldTime(): void
    {
        $this->connection->expects($this->once())->method('delete')
            ->with('bradsearch_held_product', ['store_id = ?' => 1, 'product_id IN (?)' => [10, 12]]);
        $this->connection->expects($this->once())->method('insertOnDuplicate')
            ->with(
                'bradsearch_held_product',
                [['store_id' => 1, 'product_id' => 11]],
                ['store_id']
            );

        $this->held()->update(1, [10, 12], [11]);
    }

    public function testProductsSentAtTheLimitAreMarkedSo(): void
    {
        $this->connection->expects($this->once())->method('update')
            ->with('bradsearch_held_product', ['sent_at_limit' => 1], ['store_id = ?' => 1, 'product_id IN (?)' => [11]]);

        $this->held()->update(1, [], [11], [11]);
    }

    public function testForStoreSaysWhichWereSentAtTheLimit(): void
    {
        $select = $this->createMock(\Magento\Framework\DB\Select::class);
        $select->method($this->anything())->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchPairs')->willReturn(['11' => '0', '12' => '1']);

        $this->assertSame([11 => false, 12 => true], $this->held()->forStore(1));
    }

    public function testUpdateWithNothingToChangeWritesNothing(): void
    {
        $this->connection->expects($this->never())->method('delete');
        $this->connection->expects($this->never())->method('insertOnDuplicate');
        $this->connection->expects($this->never())->method('update');

        $this->held()->update(1, [], []);
    }

    public function testHeldLongerThanReturnsProductIds(): void
    {
        $select = $this->createMock(\Magento\Framework\DB\Select::class);
        $select->method($this->anything())->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchCol')->willReturn(['11', '12']);

        $this->assertSame([11, 12], $this->held()->heldLongerThan(1, 15));
    }

    private function held(): HeldProducts
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new HeldProducts($resource);
    }
}
