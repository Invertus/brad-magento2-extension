<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Model\Sync;

use BradSearch\SearchGraphQl\Model\Sync\ResendProducts;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ResendProductsTest extends TestCase
{
    /** @var AdapterInterface&MockObject */
    private $connection;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $select = $this->createMock(Select::class);
        $select->method($this->anything())->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
    }

    public function testMarkGivesEveryProductItsOwnNewToken(): void
    {
        $rows = [];
        $this->connection->expects($this->once())->method('insertOnDuplicate')->willReturnCallback(
            function (string $table, array $data, array $fields) use (&$rows): int {
                $this->assertSame('bradsearch_resend_product', $table);
                $this->assertSame(['token'], $fields);
                $rows = $data;
                return count($data);
            }
        );

        $this->resend()->mark(3, [11, 12, 11]);

        $this->assertSame([11, 12], array_column($rows, 'product_id'));
        $this->assertSame([3, 3], array_column($rows, 'store_id'));
        $this->assertNotSame($rows[0]['token'], $rows[1]['token']);
        $this->assertSame(32, strlen($rows[0]['token']));
    }

    public function testMarkingAgainGivesANewToken(): void
    {
        $tokens = [];
        $this->connection->method('insertOnDuplicate')->willReturnCallback(
            function (string $table, array $data) use (&$tokens): int {
                $tokens[] = $data[0]['token'];
                return 1;
            }
        );

        $this->resend()->mark(3, [11]);
        $this->resend()->mark(3, [11]);

        $this->assertNotSame($tokens[0], $tokens[1]);
    }

    public function testClearRemovesOnlyTheTokensItWasGiven(): void
    {
        $this->connection->expects($this->once())->method('delete')
            ->with('bradsearch_resend_product', ['store_id = ?' => 3, 'token IN (?)' => ['a', 'b']]);

        $this->resend()->clear(3, ['a', 'b']);
    }

    public function testForStoreReturnsTokensByProduct(): void
    {
        $this->connection->method('fetchPairs')->willReturn(['11' => 'a', '12' => 'b']);

        $this->assertSame([11 => 'a', 12 => 'b'], $this->resend()->forStore(3));
    }

    public function testNothingToMarkOrClearWritesNothing(): void
    {
        $this->connection->expects($this->never())->method('insertOnDuplicate');
        $this->connection->expects($this->never())->method('delete');

        $this->resend()->mark(3, []);
        $this->resend()->clear(3, []);
    }

    private function resend(): ResendProducts
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new ResendProducts($resource);
    }
}
