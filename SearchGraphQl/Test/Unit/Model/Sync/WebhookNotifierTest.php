<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Model\Sync;

use BradSearch\SearchGraphQl\Model\Sync\WebhookNotifier;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

class WebhookNotifierTest extends TestCase
{
    /** @var Curl&MockObject */
    private $curl;

    /** @var array<string, string> */
    private array $headers = [];

    protected function setUp(): void
    {
        $this->curl = $this->createMock(Curl::class);
        $this->curl->method('addHeader')->willReturnCallback(function (string $name, string $value): void {
            $this->headers[$name] = $value;
        });
        $this->curl->method('getStatus')->willReturn(200);
        $this->curl->method('getBody')->willReturn('{}');
    }

    public function testAFilteredPushSaysSo(): void
    {
        $this->notifier()->notify([11], 1, 500, true);

        $this->assertSame('1', $this->headers['X-BradSearch-Changes-Only']);
    }

    public function testAnUnfilteredPushAfterAFilteredOneClearsTheFlag(): void
    {
        $notifier = $this->notifier();
        $notifier->notify([11], 1, 500, true);
        $notifier->notify([10, 11, 12], 1, 501);

        $this->assertSame('0', $this->headers['X-BradSearch-Changes-Only']);
    }

    private function notifier(): WebhookNotifier
    {
        $config = $this->createMock(ScopeConfigInterface::class);
        $config->method('getValue')->willReturnMap([
            ['bradsearch_search/sync/webhook_url', 'store', 1, 'https://brad.example/api/plugin/magento/products-changed'],
            ['bradsearch_search/sync/secure_token', 'store', 1, null],
        ]);

        return new WebhookNotifier($this->curl, $config, $this->createMock(EncryptorInterface::class), new NullLogger());
    }
}
