<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Plugin\CatalogGraphQl\Model\Resolver;

use BradSearch\SearchGraphQl\Model\MockData\AggregationsProvider;
use BradSearch\SearchGraphQl\Model\SearchTermFilter;
use BradSearch\SearchGraphQl\Plugin\CatalogGraphQl\Model\Resolver\Aggregations;
use GraphQL\Type\Definition\ObjectType;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class AggregationsTest extends TestCase
{
    private Aggregations $subject;
    private ScopeConfigInterface&MockObject $scopeConfigMock;
    private AggregationsProvider&MockObject $aggregationsProviderMock;
    private LoggerInterface&MockObject $loggerMock;
    private Field&MockObject $fieldMock;
    private ResolveInfo&MockObject $resolveInfoMock;

    public function testReturnsBradSearchAggregationsForSearch(): void
    {
        $expected = ['brand' => ['attribute_code' => 'brand', 'options' => []]];
        $this->scopeConfigMock->method('getValue')->willReturn(true);

        $this->aggregationsProviderMock
            ->expects($this->once())
            ->method('getAggregations')
            ->with('Aku', [])
            ->willReturn($expected);

        $result = $this->subject->aroundResolve(
            new \stdClass(),
            fn () => $this->fail('Proceed should not be called'),
            $this->fieldMock,
            null,
            $this->resolveInfoMock,
            ['layer_type' => 'search', 'search_term' => 'Aku']
        );

        $this->assertSame($expected, $result);
    }

    public function testJunkTermFallsBackWithoutCallingApiOrLogging(): void
    {
        $fallback = ['native' => true];
        $this->scopeConfigMock->method('getValue')->willReturn(true);

        $this->aggregationsProviderMock->expects($this->never())->method('getAggregations');
        $this->loggerMock->expects($this->never())->method('error');

        $result = $this->subject->aroundResolve(
            new \stdClass(),
            fn () => $fallback,
            $this->fieldMock,
            null,
            $this->resolveInfoMock,
            ['layer_type' => 'search', 'search_term' => 'tsepnye-pily/akkumulyatornye-pily.html']
        );

        $this->assertSame($fallback, $result);
    }

    public function testApiFailureLogsOneLineAndFallsBack(): void
    {
        $fallback = ['native' => true];
        $this->scopeConfigMock->method('getValue')->willReturn(true);

        $this->aggregationsProviderMock
            ->expects($this->once())
            ->method('getAggregations')
            ->willThrowException(new \Exception('BradSearch Facets API returned status code: 403'));

        $this->loggerMock
            ->expects($this->once())
            ->method('error')
            ->with(
                'BradSearch API failed, falling back to default aggregations',
                [
                    'error' => 'BradSearch Facets API returned status code: 403',
                    'search_term' => 'Karcher 8/1',
                ]
            );

        $result = $this->subject->aroundResolve(
            new \stdClass(),
            fn () => $fallback,
            $this->fieldMock,
            null,
            $this->resolveInfoMock,
            ['layer_type' => 'search', 'search_term' => 'Karcher 8/1']
        );

        $this->assertSame($fallback, $result);
    }

    protected function setUp(): void
    {
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $this->aggregationsProviderMock = $this->createMock(AggregationsProvider::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);
        $this->fieldMock = $this->createMock(Field::class);

        $this->resolveInfoMock = $this->createMock(ResolveInfo::class);
        $this->resolveInfoMock->path = ['products', 'aggregations'];
        $this->resolveInfoMock->variableValues = [];
        $this->resolveInfoMock->parentType = new ObjectType(['name' => 'Products', 'fields' => []]);

        $storeMock = $this->createMock(Store::class);
        $storeMock->method('getId')->willReturn(1);
        $storeManagerMock->method('getStore')->willReturn($storeMock);

        $this->subject = new Aggregations(
            $this->scopeConfigMock,
            $storeManagerMock,
            $this->aggregationsProviderMock,
            $this->loggerMock,
            new SearchTermFilter()
        );
    }
}
