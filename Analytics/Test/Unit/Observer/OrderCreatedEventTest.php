<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\Analytics\Test\Unit\Observer;

use BradSearch\Analytics\Model\OrderEvents\OrderPaidEventBuilder;
use BradSearch\Analytics\Model\OrderEvents\Outbox;
use BradSearch\Analytics\Observer\OrderCreatedEvent;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\DataObject;
use Magento\Framework\Event\Observer;
use Magento\Sales\Model\Order;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class OrderCreatedEventTest extends TestCase
{
    /** @var Outbox&MockObject */
    private $outbox;

    /** @var OrderPaidEventBuilder&MockObject */
    private $builder;

    /** @var LoggerInterface&MockObject */
    private $logger;

    protected function setUp(): void
    {
        $this->outbox = $this->createMock(Outbox::class);
        $this->builder = $this->createMock(OrderPaidEventBuilder::class);
        $this->builder->method('build')->willReturn(['type' => 'event']);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    private function observe(array $config): void
    {
        $scopeConfig = $this->createMock(ScopeConfigInterface::class);
        $scopeConfig->method('isSetFlag')->willReturn($config['enabled'] ?? true);
        $scopeConfig->method('getValue')->willReturnCallback(function ($path) use ($config) {
            $values = [
                'bradsearch_analytics/general/api_url' => $config['api_url'] ?? 'https://analytics.example.com',
                'bradsearch_analytics/general/website_id' => $config['website_id'] ?? 'website-1',
            ];

            return $values[$path] ?? null;
        });

        $order = $this->createMock(Order::class);
        $order->method('getId')->willReturn(42);
        $order->method('getStoreId')->willReturn(5);

        $observer = new Observer(['event' => new DataObject(['order' => $order])]);

        (new OrderCreatedEvent($this->builder, $this->outbox, $scopeConfig, $this->logger))->execute($observer);
    }

    public function testQueuesThePaidOrderWithoutSendingAnything(): void
    {
        $this->outbox->expects($this->once())->method('add')->with(42, 5, '{"type":"event"}', $this->matchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/'));

        $this->observe([]);
    }

    public function testNothingIsQueuedWhenAnalyticsIsOffOrNotConfigured(): void
    {
        $this->outbox->expects($this->never())->method('add');

        $this->observe(['enabled' => false]);
        $this->observe(['api_url' => '']);
        $this->observe(['website_id' => '']);
    }

    public function testAQueueErrorNeverBreaksTheCheckout(): void
    {
        $this->outbox->method('add')->willThrowException(new \RuntimeException('table missing'));
        $this->logger->expects($this->once())->method('error');

        $this->observe([]);
    }
}
