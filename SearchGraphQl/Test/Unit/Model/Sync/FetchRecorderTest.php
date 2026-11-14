<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Model\Sync;

use BradSearch\SearchGraphQl\Model\Sync\ChangelogReader;
use BradSearch\SearchGraphQl\Model\Sync\FetchRecorder;
use BradSearch\SearchGraphQl\Model\Sync\ResendProducts;
use BradSearch\SearchGraphQl\Model\Sync\WebhookNotifier;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class FetchRecorderTest extends TestCase
{
    /** @var AdapterInterface&MockObject */
    private $connection;

    /** @var ChangelogReader&MockObject */
    private $changelogReader;

    /** @var ResendProducts&MockObject */
    private $resendProducts;

    /** @var WebhookNotifier&MockObject */
    private $notifier;

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $select = $this->createMock(Select::class);
        $select->method($this->anything())->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->changelogReader = $this->createMock(ChangelogReader::class);
        $this->resendProducts = $this->createMock(ResendProducts::class);
        $this->resendProducts->method('isAvailable')->willReturn(true);
        $this->notifier = $this->createMock(WebhookNotifier::class);
        $this->notifier->method('isSyncEnabled')->willReturn(true);
    }

    public function testTheCursorIsTheStoresSyncState(): void
    {
        $this->connection->method('fetchOne')->willReturn('400');

        $this->assertSame(400, $this->recorder()->cursor(1));
    }

    public function testNoCursorWithoutLiveSyncSyncStateOrResendTable(): void
    {
        $this->connection->method('fetchOne')->willReturn(false);
        $this->assertNull($this->recorder()->cursor(1), 'no sync state yet');

        $this->notifier = $this->createMock(WebhookNotifier::class);
        $this->notifier->method('isSyncEnabled')->willReturn(false);
        $this->assertNull($this->recorder()->cursor(1), 'live sync off');

        $this->notifier = $this->createMock(WebhookNotifier::class);
        $this->notifier->method('isSyncEnabled')->willReturn(true);
        $this->resendProducts = $this->createMock(ResendProducts::class);
        $this->resendProducts->method('isAvailable')->willReturn(false);
        $this->assertNull($this->recorder()->cursor(1), 'no resend table');
    }

    public function testAFailedCursorReadRecordsNothing(): void
    {
        $this->connection->method('fetchOne')->willThrowException(new \RuntimeException('db gone'));

        $this->assertNull($this->recorder()->cursor(1));
    }

    public function testFetchedProductsWithAChangeAfterTheCursorAreMarked(): void
    {
        $this->changelogReader->expects($this->once())->method('getProductsChangedAfter')
            ->with(400, [11, 12, 13])->willReturn([12]);
        $this->resendProducts->expects($this->once())->method('mark')->with(1, [12]);

        $recorder = $this->recorder();
        $recorder->record(1, 400, [11, 12, 13, 11]);
        $recorder->flush();
    }

    public function testAPageMarksTheChangedProductsInItsRange(): void
    {
        $this->changelogReader->expects($this->once())->method('getProductsChangedAfterInRange')
            ->with(400, 4, null)->willReturn([7]);
        $this->resendProducts->expects($this->once())->method('mark')->with(2, [7]);

        $recorder = $this->recorder();
        $recorder->recordRange(2, 400, 4, null);
        $recorder->flush();
    }

    public function testNothingChangedMarksNothing(): void
    {
        $this->changelogReader->method('getProductsChangedAfter')->willReturn([]);
        $this->resendProducts->expects($this->never())->method('mark');

        $recorder = $this->recorder();
        $recorder->record(1, 400, [11]);
        $recorder->flush();
    }

    public function testWithoutACursorNothingIsRecorded(): void
    {
        $this->changelogReader->expects($this->never())->method('getProductsChangedAfter');
        $this->changelogReader->expects($this->never())->method('getProductsChangedAfterInRange');

        $recorder = $this->recorder();
        $recorder->record(1, null, [11]);
        $recorder->recordRange(1, null, 0, null);
        $recorder->flush();
    }

    public function testAFlushHandlesEachFetchOnceAndAFailureDoesNotStopTheOthers(): void
    {
        $this->changelogReader->method('getProductsChangedAfter')->willReturnCallback(function (int $cursor) {
            if ($cursor === 1) {
                throw new \RuntimeException('db gone');
            }
            return [11];
        });
        $this->resendProducts->expects($this->once())->method('mark')->with(2, [11]);

        $recorder = $this->recorder();
        $recorder->record(1, 1, [10]);
        $recorder->record(2, 2, [11]);
        $recorder->flush();
        $recorder->flush();
    }

    private function recorder(): FetchRecorder
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new FetchRecorder($resource, $this->changelogReader, $this->resendProducts, $this->notifier, new NullLogger());
    }
}
