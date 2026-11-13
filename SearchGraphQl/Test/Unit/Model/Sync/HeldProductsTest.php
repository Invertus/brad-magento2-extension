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

    public function testUpdateWithNothingToChangeWritesNothing(): void
    {
        $this->connection->expects($this->never())->method('delete');
        $this->connection->expects($this->never())->method('insertOnDuplicate');

        $this->held()->update(1, [], []);
    }

    private function held(): HeldProducts
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new HeldProducts($resource);
    }
}
