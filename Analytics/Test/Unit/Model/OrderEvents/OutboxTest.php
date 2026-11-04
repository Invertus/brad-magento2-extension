<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\Analytics\Test\Unit\Model\OrderEvents;

use BradSearch\Analytics\Model\OrderEvents\Outbox;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class OutboxTest extends TestCase
{
    /** @var AdapterInterface&MockObject */
    private $connection;

    /** @var Outbox */
    private $outbox;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);
        $this->outbox = new Outbox($resource);
    }

    public function testAddInsertsOncePerOrder(): void
    {
        $this->connection->expects($this->exactly(2))->method('insertArray')
            ->with('bradsearch_order_outbox', $this->anything(), [[42, 5, '{}', 'pending', 0, '2026-01-01 10:00:00', '2026-01-01 10:00:00', '2026-01-01 10:00:00']], AdapterInterface::INSERT_IGNORE)
            ->willReturnOnConsecutiveCalls(1, 0);

        $this->assertTrue($this->outbox->add(42, 5, '{}', '2026-01-01 10:00:00'));
        $this->assertFalse($this->outbox->add(42, 5, '{}', '2026-01-01 10:00:00'));
    }

    public function testClaimLeasesOnlyARowThatIsStillDue(): void
    {
        $select = $this->createMock(Select::class);
        $select->method($this->anything())->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturn(['entity_id' => '7', 'order_id' => '42', 'store_id' => '5', 'body' => '{}', 'attempts' => '2']);
        $this->connection->expects($this->once())->method('update')
            ->with('bradsearch_order_outbox', ['next_attempt_at' => '2026-01-01 10:02:00'], [
                'entity_id = ?' => 7,
                'status = ?' => 'pending',
                'next_attempt_at <= ?' => '2026-01-01 10:00:00',
            ])
            ->willReturn(1);

        $this->assertSame(
            ['entity_id' => 7, 'order_id' => 42, 'store_id' => 5, 'body' => '{}', 'attempts' => 2, 'lease' => '2026-01-01 10:02:00'],
            $this->outbox->claimNext('2026-01-01 10:00:00', '2026-01-01 10:02:00')
        );
    }

    public function testALostClaimReturnsNothing(): void
    {
        $select = $this->createMock(Select::class);
        $select->method($this->anything())->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchRow')->willReturn(['entity_id' => '7', 'order_id' => '42', 'store_id' => '5', 'body' => '{}', 'attempts' => '0']);
        $this->connection->method('update')->willReturn(0);

        $this->assertNull($this->outbox->claimNext('2026-01-01 10:00:00', '2026-01-01 10:02:00'));
    }

    public function testResultWritesApplyOnlyWhileTheRowHoldsItsLease(): void
    {
        $this->connection->expects($this->once())->method('update')
            ->with('bradsearch_order_outbox', [
                'status' => 'sent',
                'attempts' => 3,
                'last_error' => null,
                'updated_at' => '2026-01-01 10:00:05',
            ], [
                'entity_id = ?' => 7,
                'status = ?' => 'pending',
                'next_attempt_at = ?' => '2026-01-01 10:02:00',
            ]);

        $this->outbox->markSent(7, '2026-01-01 10:02:00', 3, '2026-01-01 10:00:05');
    }

    public function testPurgeDeletesOldSentAndFailedRowsOnly(): void
    {
        $this->connection->method('quote')->willReturnCallback(function ($value) {
            return "'" . $value . "'";
        });
        $this->connection->expects($this->once())->method('delete')->with('bradsearch_order_outbox', [
            "(status = 'sent' AND updated_at < '2025-12-25 10:00:00') OR (status = 'failed' AND updated_at < '2025-12-02 10:00:00')",
        ]);

        $this->outbox->purge('2025-12-25 10:00:00', '2025-12-02 10:00:00');
    }
}
