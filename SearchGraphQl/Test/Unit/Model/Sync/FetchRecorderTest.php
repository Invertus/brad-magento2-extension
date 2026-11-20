<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Model\Sync;

use BradSearch\SearchGraphQl\Model\Sync\ChangeFilter;
use BradSearch\SearchGraphQl\Model\Sync\ChangelogReader;
use BradSearch\SearchGraphQl\Model\Sync\FetchRecorder;
use BradSearch\SearchGraphQl\Model\Sync\HeldProducts;
use BradSearch\SearchGraphQl\Model\Sync\ProductRelations;
use BradSearch\SearchGraphQl\Model\Sync\ResendProducts;
use BradSearch\SearchGraphQl\Model\Sync\StockStatusSnapshot;
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

    /** @var HeldProducts&MockObject */
    private $heldProducts;

    /** @var ChangeFilter&MockObject */
    private $changeFilter;

    /** @var ChangelogReader&MockObject */
    private $stockChangelogReader;

    /** @var ProductRelations&MockObject */
    private $relations;

    /** @var StockStatusSnapshot&MockObject */
    private $snapshot;

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
        $this->heldProducts = $this->createMock(HeldProducts::class);
        $this->heldProducts->method('isAvailable')->willReturn(true);
        $this->heldProducts->method('notSentAmong')->willReturn([]);
        $this->changeFilter = $this->createMock(ChangeFilter::class);
        $this->stockChangelogReader = $this->createMock(ChangelogReader::class);
        $this->relations = $this->createMock(ProductRelations::class);
        $this->relations->method('childrenOf')->willReturn([]);
        $this->relations->method('childrenInRange')->willReturn([]);
        $this->snapshot = $this->createMock(StockStatusSnapshot::class);
        $this->snapshot->method('isAvailable')->willReturn(true);
        $this->snapshot->method('changedAmong')->willReturn([]);
    }

    public function testTheCursorsAreTheStoresSyncState(): void
    {
        $this->connection->method('tableColumnExists')->willReturn(true);
        $this->connection->method('fetchRow')->willReturn(['last_version_id' => '400', 'last_stock_version_id' => '70']);

        $this->assertSame([400, 70], $this->recorder()->cursor(1));
    }

    public function testNoStockCursorBeforeTheSyncReadsTheStockChangelog(): void
    {
        $this->connection->method('tableColumnExists')->willReturn(true);
        $this->connection->method('fetchRow')->willReturn(['last_version_id' => '400', 'last_stock_version_id' => null]);

        $this->assertSame([400, null], $this->recorder()->cursor(1));
    }

    public function testNoStockCursorWithoutItsColumn(): void
    {
        $this->connection->method('tableColumnExists')->willReturn(false);
        $this->connection->method('fetchRow')->willReturn(['last_version_id' => '400']);

        $this->assertSame([400, null], $this->recorder()->cursor(1));
    }

    public function testNoCursorWithoutLiveSyncSyncStateOrResendTable(): void
    {
        $this->connection->method('fetchRow')->willReturn(false);
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
        $this->connection->method('fetchRow')->willThrowException(new \RuntimeException('db gone'));

        $this->assertNull($this->recorder()->cursor(1));
    }

    public function testFetchedProductsWithAChangeAfterTheCursorAreMarked(): void
    {
        $this->changelogReader->expects($this->once())->method('getProductsChangedAfter')
            ->with(400, [11, 12, 13])->willReturn([12]);
        $this->resendProducts->expects($this->once())->method('mark')->with(1, [12]);

        $recorder = $this->recorder();
        $recorder->record(1, [400, null], [11, 12, 13, 11]);
        $recorder->flush();
    }

    public function testAPageMarksTheChangedProductsInItsRange(): void
    {
        $this->changelogReader->expects($this->once())->method('getProductsChangedAfterInRange')
            ->with(400, 4, null)->willReturn([7]);
        $this->resendProducts->expects($this->once())->method('mark')->with(2, [7]);

        $recorder = $this->recorder();
        $recorder->recordRange(2, [400, null], 4, null);
        $recorder->flush();
    }

    public function testAFetchedProductHeldForTheIndexIsMarked(): void
    {
        // The cursor moved past the held product's changes, but no hash describes them: BradSearch may have
        // read a state that is undone before the product is released.
        $this->changelogReader->method('getProductsChangedAfter')->willReturn([12]);
        $this->heldProducts = $this->createMock(HeldProducts::class);
        $this->heldProducts->method('isAvailable')->willReturn(true);
        $this->heldProducts->expects($this->once())->method('notSentAmong')
            ->with(1, [11, 12, 13], 0, null)->willReturn([11, 12]);
        $this->resendProducts->expects($this->once())->method('mark')->with(1, [12, 11]);

        $recorder = $this->recorder();
        $recorder->record(1, [400, null], [11, 12, 13]);
        $recorder->flush();
    }

    public function testAPageMarksTheHeldProductsInItsRange(): void
    {
        $this->changelogReader->method('getProductsChangedAfterInRange')->willReturn([]);
        $this->heldProducts = $this->createMock(HeldProducts::class);
        $this->heldProducts->method('isAvailable')->willReturn(true);
        $this->heldProducts->expects($this->once())->method('notSentAmong')
            ->with(2, null, 4, 90)->willReturn([50]);
        $this->resendProducts->expects($this->once())->method('mark')->with(2, [50]);

        $recorder = $this->recorder();
        $recorder->recordRange(2, [400, null], 4, 90);
        $recorder->flush();
    }

    public function testWithoutTheHeldTableOnlyTheChangelogCounts(): void
    {
        $this->changelogReader->method('getProductsChangedAfter')->willReturn([12]);
        $this->heldProducts = $this->createMock(HeldProducts::class);
        $this->heldProducts->method('isAvailable')->willReturn(false);
        $this->heldProducts->expects($this->never())->method('notSentAmong');
        $this->resendProducts->expects($this->once())->method('mark')->with(1, [12]);

        $recorder = $this->recorder();
        $recorder->record(1, [400, null], [11, 12]);
        $recorder->flush();
    }

    public function testAProductThatCouldNotBeBuiltHasItsHashForgottenNotAMark(): void
    {
        // Its next changelog entry sends it again. A mark would send a product that keeps failing every run.
        $forgotten = [];
        $this->changeFilter->expects($this->exactly(2))->method('forget')
            ->willReturnCallback(function (array $productIds, int $storeId) use (&$forgotten): void {
                $forgotten[] = [$productIds, $storeId];
            });
        $this->resendProducts->expects($this->never())->method('mark');

        $recorder = $this->recorder();
        $recorder->recordFailure(1, 12);
        $recorder->recordFailure(1, 13);
        $recorder->recordFailure(2, 40);
        $recorder->flush();
        $recorder->flush();

        $this->assertSame([[[12, 13], 1], [[40], 2]], $forgotten);
    }

    public function testAFailedForgetDoesNotStopTheMarks(): void
    {
        $this->changeFilter->method('forget')->willThrowException(new \RuntimeException('db gone'));
        $this->changelogReader->method('getProductsChangedAfter')->willReturn([11]);
        $this->resendProducts->expects($this->once())->method('mark')->with(1, [11]);

        $recorder = $this->recorder();
        $recorder->recordFailure(1, 12);
        $recorder->record(1, [400, null], [11, 12]);
        $recorder->flush();
    }

    public function testNothingChangedMarksNothing(): void
    {
        $this->changelogReader->method('getProductsChangedAfter')->willReturn([]);
        $this->resendProducts->expects($this->never())->method('mark');

        $recorder = $this->recorder();
        $recorder->record(1, [400, null], [11]);
        $recorder->flush();
    }

    public function testAFetchedProductWithAStockChangeAfterTheStockCursorIsMarked(): void
    {
        $this->changelogReader->method('getProductsChangedAfter')->with(400, [11, 12])->willReturn([]);
        $this->stockChangelogReader->expects($this->once())->method('getProductsChangedAfter')
            ->with(70, [11, 12])->willReturn([12]);
        $this->resendProducts->expects($this->once())->method('mark')->with(1, [12]);

        $recorder = $this->recorder();
        $recorder->record(1, [400, 70], [11, 12]);
        $recorder->flush();
    }

    public function testAPageChecksTheStockChangelogInItsRange(): void
    {
        $this->changelogReader->method('getProductsChangedAfterInRange')->willReturn([]);
        $this->stockChangelogReader->expects($this->once())->method('getProductsChangedAfterInRange')
            ->with(70, 4, 90)->willReturn([50]);
        $this->resendProducts->expects($this->once())->method('mark')->with(2, [50]);

        $recorder = $this->recorder();
        $recorder->recordRange(2, [400, 70], 4, 90);
        $recorder->flush();
    }

    public function testWithoutAStockCursorTheStockChangelogIsNotRead(): void
    {
        $this->changelogReader->method('getProductsChangedAfter')->willReturn([]);
        $this->stockChangelogReader->expects($this->never())->method('getProductsChangedAfter');

        $recorder = $this->recorder();
        $recorder->record(1, [400, null], [11]);
        $recorder->flush();
    }

    public function testAFetchedBundleIsMarkedWhenAPartChangedAfterTheCursor(): void
    {
        // The stock index writes the bundle's status when it indexes the part, with no row for the bundle.
        $this->relations = $this->createMock(ProductRelations::class);
        $this->relations->method('childrenOf')->with([72, 11])->willReturn([72 => [90, 91]]);
        $this->changelogReader->method('getProductsChangedAfter')->willReturn([]);
        $this->stockChangelogReader->method('getProductsChangedAfter')->willReturnCallback(
            function (int $cursor, array $ids): array {
                return $ids === [90, 91] ? [91] : [];
            }
        );
        $this->resendProducts->expects($this->once())->method('mark')->with(1, [72]);

        $recorder = $this->recorder();
        $recorder->record(1, [400, 70], [72, 11]);
        $recorder->flush();
    }

    public function testAPageMarksTheParentsInItsRangeWithAChangedChild(): void
    {
        $this->relations = $this->createMock(ProductRelations::class);
        $this->relations->method('childrenInRange')->with(4, 90)->willReturn([50 => [200], 60 => [201]]);
        $this->changelogReader->method('getProductsChangedAfterInRange')->willReturn([]);
        $this->changelogReader->method('getProductsChangedAfter')->with(400, [200, 201])->willReturn([201]);
        $this->resendProducts->expects($this->once())->method('mark')->with(2, [60]);

        $recorder = $this->recorder();
        $recorder->recordRange(2, [400, null], 4, 90);
        $recorder->flush();
    }

    public function testAFetchedProductWhoseStockStatusTheSyncHasNotSeenIsMarked(): void
    {
        // An import can write the status with no changelog row; the next run sends it, but an undo before
        // that run would leave BradSearch with the status it read.
        $this->changelogReader->method('getProductsChangedAfter')->willReturn([]);
        $this->snapshot = $this->createMock(StockStatusSnapshot::class);
        $this->snapshot->method('isAvailable')->willReturn(true);
        $this->snapshot->expects($this->once())->method('changedAmong')->with([11, 12], 0, null)->willReturn([12]);
        $this->resendProducts->expects($this->once())->method('mark')->with(1, [12]);

        $recorder = $this->recorder();
        $recorder->record(1, [400, null], [11, 12]);
        $recorder->flush();
    }

    public function testAPageChecksTheStockStatusInItsRange(): void
    {
        $this->changelogReader->method('getProductsChangedAfterInRange')->willReturn([]);
        $this->snapshot = $this->createMock(StockStatusSnapshot::class);
        $this->snapshot->method('isAvailable')->willReturn(true);
        $this->snapshot->expects($this->once())->method('changedAmong')->with(null, 4, 90)->willReturn([50]);
        $this->resendProducts->expects($this->once())->method('mark')->with(2, [50]);

        $recorder = $this->recorder();
        $recorder->recordRange(2, [400, null], 4, 90);
        $recorder->flush();
    }

    public function testWithoutTheStockStatusTableOnlyTheChangelogsCount(): void
    {
        $this->changelogReader->method('getProductsChangedAfter')->willReturn([11]);
        $this->snapshot = $this->createMock(StockStatusSnapshot::class);
        $this->snapshot->method('isAvailable')->willReturn(false);
        $this->snapshot->expects($this->never())->method('changedAmong');
        $this->resendProducts->expects($this->once())->method('mark')->with(1, [11]);

        $recorder = $this->recorder();
        $recorder->record(1, [400, null], [11, 12]);
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
        $recorder->record(1, [1, null], [10]);
        $recorder->record(2, [2, null], [11]);
        $recorder->flush();
        $recorder->flush();
    }

    private function recorder(): FetchRecorder
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new FetchRecorder(
            $resource,
            $this->changelogReader,
            $this->resendProducts,
            $this->heldProducts,
            $this->changeFilter,
            $this->notifier,
            new NullLogger(),
            $this->stockChangelogReader,
            $this->relations,
            $this->snapshot
        );
    }
}
