<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Model\MockData;

use BradSearch\SearchGraphQl\Model\Api\Client;
use BradSearch\SearchGraphQl\Model\MockData\AggregationsProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AggregationsProviderTest extends TestCase
{
    private AggregationsProvider $subject;
    private Client&MockObject $clientMock;
    private LoggerInterface&MockObject $loggerMock;

    public function testMapsV2FacetsToAggregations(): void
    {
        $this->clientMock
            ->expects($this->once())
            ->method('fetchFacets')
            ->with('drill', [])
            ->willReturn(['facets' => ['brand' => [['value' => 'Bosch', 'count' => 3]]]]);

        $result = $this->subject->getAggregations('drill');

        $this->assertArrayHasKey('brand', $result);
        $this->assertSame('Bosch', $result['brand']['options'][0]['value']);
        $this->assertSame(3, $result['brand']['options'][0]['count']);
    }

    public function testApiFailureIsRethrownWithoutLogging(): void
    {
        $this->clientMock
            ->expects($this->once())
            ->method('fetchFacets')
            ->willThrowException(new \Exception('BradSearch Facets API returned status code: 403'));

        $this->loggerMock->expects($this->never())->method('error');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('BradSearch Facets API returned status code: 403');

        $this->subject->getAggregations('Karcher 8/1');
    }

    protected function setUp(): void
    {
        $this->clientMock = $this->createMock(Client::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->subject = new AggregationsProvider($this->clientMock, $this->loggerMock);
    }
}
