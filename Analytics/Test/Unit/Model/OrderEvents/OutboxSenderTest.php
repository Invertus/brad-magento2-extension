<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\Analytics\Test\Unit\Model\OrderEvents;

use BradSearch\Analytics\Model\OrderEvents\Outbox;
use BradSearch\Analytics\Model\OrderEvents\OutboxSender;
use BradSearch\Analytics\Model\OrderEvents\RetryPolicy;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Lock\LockManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OutboxSenderTest extends TestCase
{
    private const NOW = 1793362993;

    /** @var Outbox&MockObject */
    private $outbox;

    /** @var Curl&MockObject */
    private $curl;

    /** @var LockManagerInterface&MockObject */
    private $lockManager;

    /** @var LoggerInterface&MockObject */
    private $logger;

    /** @var string */
    private $apiUrl = 'https://analytics.example.com';

    protected function setUp(): void
    {
        $this->outbox = $this->createMock(Outbox::class);
        $this->curl = $this->createMock(Curl::class);
        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->lockManager->method('lock')->willReturn(true);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function sender(): OutboxSender
    {
        $curlFactory = $this->createMock(CurlFactory::class);
        $curlFactory->method('create')->willReturn($this->curl);

        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(function () {
            return $this->apiUrl;
        });

        return new OutboxSender($this->outbox, $curlFactory, $scopeConfig, $this->lockManager, new RetryPolicy(), $this->logger, function () {
            return self::NOW;
        });
    }

    /**
     * @param int $attempts attempts made before this run
     */
    private function row(int $id, int $attempts = 0): array
    {
        return ['entity_id' => $id, 'order_id' => 100 + $id, 'store_id' => 5, 'body' => '{"order":' . $id . '}', 'attempts' => $attempts, 'lease' => 'lease-' . $id];
    }

    private function utc(int $offset): string
    {
        return gmdate('Y-m-d H:i:s', self::NOW + $offset);
    }

    public function testSendsDueOrdersAndMarksThemSent(): void
    {
        $this->outbox->method('claimNext')->willReturnOnConsecutiveCalls($this->row(1), $this->row(2), null);
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->expects($this->exactly(2))->method('post')->with('https://analytics.example.com/api/send', $this->stringStartsWith('{"order":'));
        $this->outbox->expects($this->exactly(2))->method('markSent');

        $this->assertSame(['sent' => 2, 'retry' => 0, 'failed' => 0], $this->sender()->sendDue(40));
    }

    public function testA5xxIsTriedAgainAfterAMinuteAndStopsTheRun(): void
    {
        $this->outbox->expects($this->once())->method('claimNext')->willReturn($this->row(1));
        $this->curl->method('getStatus')->willReturn(503);
        $this->outbox->expects($this->once())->method('retryLater')->with(1, 'lease-1', 1, $this->utc(60), 'HTTP 503', $this->utc(0));

        $this->assertSame(['sent' => 0, 'retry' => 1, 'failed' => 0], $this->sender()->sendDue(40));
    }

    public function testATimeoutIsTriedAgain(): void
    {
        $this->outbox->method('claimNext')->willReturn($this->row(1));
        $this->curl->method('post')->willThrowException(new \Exception('Operation timed out after 3001 milliseconds'));
        $this->outbox->expects($this->once())->method('retryLater')->with(1, 'lease-1', 1, $this->utc(60), $this->stringContains('timed out'), $this->utc(0));

        $this->sender()->sendDue(40);
    }

    public function testGivesUpAfterTheSeventhFailedAttemptWithOneWarning(): void
    {
        $this->outbox->method('claimNext')->willReturnOnConsecutiveCalls($this->row(1, 6), null);
        $this->curl->method('getStatus')->willReturn(503);
        $this->outbox->expects($this->once())->method('markFailed')->with(1, 'lease-1', 7, 'HTTP 503', $this->utc(0));
        $this->logger->expects($this->once())->method('warning');

        $this->sender()->sendDue(40);
    }

    public function testARejectedPayloadFailsAtOnce(): void
    {
        $this->outbox->method('claimNext')->willReturnOnConsecutiveCalls($this->row(1), null);
        $this->curl->method('getStatus')->willReturn(400);
        $this->outbox->expects($this->once())->method('markFailed')->with(1, 'lease-1', 1, 'HTTP 400', $this->utc(0));
        $this->outbox->expects($this->never())->method('retryLater');

        $this->sender()->sendDue(40);
    }

    public function testAMissingApiUrlKeepsTheOrderWaitingWithoutCalling(): void
    {
        $this->apiUrl = '';
        $this->outbox->expects($this->once())->method('claimNext')->willReturn($this->row(1));
        $this->curl->expects($this->never())->method('post');
        $this->outbox->expects($this->once())->method('retryLater')->with(1, 'lease-1', 1, $this->utc(60), 'Analytics API URL is not set', $this->utc(0));
        $this->outbox->expects($this->never())->method('markFailed');

        $this->sender()->sendDue(40);
    }

    public function testAnEndpointErrorKeepsTheQueueAndStopsTheRun(): void
    {
        $this->outbox->expects($this->once())->method('claimNext')->willReturn($this->row(1));
        $this->curl->method('getStatus')->willReturn(403);
        $this->outbox->expects($this->once())->method('retryLater')->with(1, 'lease-1', 1, $this->utc(60), 'HTTP 403', $this->utc(0));
        $this->outbox->expects($this->never())->method('markFailed');

        $this->assertSame(['sent' => 0, 'retry' => 1, 'failed' => 0], $this->sender()->sendDue(40));
    }

    public function testDoesNothingWhileAnotherRunHoldsTheLock(): void
    {
        $this->lockManager = $this->createMock(LockManagerInterface::class);
        $this->lockManager->method('lock')->willReturn(false);
        $this->lockManager->expects($this->never())->method('unlock');
        $this->outbox->expects($this->never())->method('claimNext');

        $this->assertSame(['sent' => 0, 'retry' => 0, 'failed' => 0], $this->sender()->sendDue(40));
    }

    public function testReleasesTheLockWhenTheRunThrows(): void
    {
        $this->outbox->method('claimNext')->willThrowException(new \RuntimeException('database gone'));
        $this->lockManager->expects($this->once())->method('unlock');

        $this->expectException(\RuntimeException::class);
        $this->sender()->sendDue(40);
    }

    public function testStopsAtTheLimitAndPurgesOldRows(): void
    {
        $this->outbox->method('claimNext')->willReturnOnConsecutiveCalls($this->row(1), $this->row(2), $this->row(3));
        $this->curl->method('getStatus')->willReturn(200);
        $this->outbox->expects($this->once())->method('purge')->with($this->utc(-7 * 86400), $this->utc(-30 * 86400));

        $this->assertSame(2, $this->sender()->sendDue(40, 2)['sent']);
    }

    public function testAContinueAnswerIsTriedAgainNotFailed(): void
    {
        $this->outbox->method('claimNext')->willReturn($this->row(1));
        $this->curl->method('getStatus')->willReturn(100);
        $this->outbox->expects($this->never())->method('markFailed');
        $this->outbox->expects($this->once())->method('retryLater');

        $this->sender()->sendDue(40);
    }

    public function testTurnsOffExpectContinueAndWaitsUpToTenSeconds(): void
    {
        $this->outbox->method('claimNext')->willReturnOnConsecutiveCalls($this->row(1), null);
        $this->curl->method('getStatus')->willReturn(200);
        $headers = [];
        $this->curl->method('addHeader')->willReturnCallback(function ($name, $value) use (&$headers) {
            $headers[$name] = $value;
        });
        $options = [];
        $this->curl->method('setOption')->willReturnCallback(function ($name, $value) use (&$options) {
            $options[$name] = $value;
        });

        $this->sender()->sendDue(40);

        $this->assertSame('', $headers['Expect']);
        $this->assertSame(10, $options[CURLOPT_TIMEOUT]);
    }

    public function testAStoreWhoseConfigCannotBeReadWaitsWithItsAttemptCounted(): void
    {
        $curlFactory = $this->createMock(CurlFactory::class);
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willThrowException(new \RuntimeException('store not found'));
        $this->outbox->expects($this->once())->method('claimNext')->willReturn($this->row(1));
        $this->outbox->expects($this->once())->method('retryLater')->with(1, 'lease-1', 1, $this->utc(60), 'Analytics API URL is not set', $this->utc(0));

        (new OutboxSender($this->outbox, $curlFactory, $scopeConfig, $this->lockManager, new RetryPolicy(), $this->logger, function () {
            return self::NOW;
        }))->sendDue(40);
    }
}
