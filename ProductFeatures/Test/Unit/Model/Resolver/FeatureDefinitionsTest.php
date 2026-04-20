<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\ProductFeatures\Test\Unit\Model\Resolver;

use BradSearch\ProductFeatures\Model\Resolver\FeatureDefinitions;
use BradSearch\SearchGraphQl\Model\Api\Auth\ApiKeyValidator;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use ReflectionClass;

/**
 * Unit tests for FeatureDefinitions resolver.
 *
 * Focus: has_unit derivation from mm_format and graceful fallback when
 * the Magenmagic_Sync-installed mm_format column is absent.
 */
class FeatureDefinitionsTest extends TestCase
{
    /**
     * @var FeatureDefinitions
     */
    private FeatureDefinitions $resolver;

    /**
     * @var ApiKeyValidator|MockObject
     */
    private $apiKeyValidatorMock;

    /**
     * @var ResourceConnection|MockObject
     */
    private $resourceConnectionMock;

    /**
     * @var AdapterInterface|MockObject
     */
    private $connectionMock;

    protected function setUp(): void
    {
        parent::setUp();

        $this->apiKeyValidatorMock = $this->getMockBuilder(ApiKeyValidator::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->resourceConnectionMock = $this->getMockBuilder(ResourceConnection::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->connectionMock = $this->getMockBuilder(AdapterInterface::class)
            ->disableOriginalConstructor()
            ->getMockForAbstractClass();

        $this->resourceConnectionMock->method('getConnection')->willReturn($this->connectionMock);
        $this->resourceConnectionMock->method('getTableName')->willReturnArgument(0);

        $this->resolver = new FeatureDefinitions(
            $this->apiKeyValidatorMock,
            $this->resourceConnectionMock
        );
    }

    /**
     * Invoke a private method via reflection.
     *
     * @param object $object
     * @param string $method
     * @param array $args
     * @return mixed
     */
    private function invokePrivate(object $object, string $method, array $args = [])
    {
        $reflection = new ReflectionClass($object);
        $reflectionMethod = $reflection->getMethod($method);
        $reflectionMethod->setAccessible(true);

        return $reflectionMethod->invokeArgs($object, $args);
    }

    /**
     * Stub the DB chain so getFeatureDefinitions() resolves to the given fetchAll rows.
     *
     * @param array $rows
     */
    private function stubFetchAll(array $rows): void
    {
        $selectMock = $this->getMockBuilder(Select::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['from', 'joinInner', 'joinLeft', 'where'])
            ->getMock();

        $selectMock->method('from')->willReturnSelf();
        $selectMock->method('joinInner')->willReturnSelf();
        $selectMock->method('joinLeft')->willReturnSelf();
        $selectMock->method('where')->willReturnSelf();

        $this->connectionMock->method('select')->willReturn($selectMock);
        $this->connectionMock->method('fetchAll')->willReturn($rows);
    }

    /**
     * @dataProvider hasUnitProvider
     */
    public function testHasUnitDerivedFromMmFormat(?string $mmFormat, bool $expected): void
    {
        $this->connectionMock->method('tableColumnExists')->willReturn(true);
        $this->stubFetchAll([
            [
                'attribute_code' => 'some_attr',
                'attribute_id' => 123,
                'frontend_label' => 'Some Attr',
                'mm_format' => $mmFormat,
                'is_searchable' => 1,
                'is_filterable_in_search' => 0,
                'position' => 10,
                'store_label' => null,
            ],
        ]);

        $result = $this->invokePrivate($this->resolver, 'getFeatureDefinitions', [1]);

        $this->assertCount(1, $result);
        $this->assertSame($expected, $result[0]['has_unit']);
    }

    /**
     * @return array<string, array{string|null, bool}>
     */
    public static function hasUnitProvider(): array
    {
        return [
            'kg format marks attribute as unit feature' => ['%s kg', true],
            'mm format marks attribute as unit feature' => ['%s mm', true],
            'watts format marks attribute as unit feature' => ['%s W', true],
            'percent format marks attribute as unit feature' => ['%s%%', true],
            'decimal kg format marks attribute as unit feature' => ['%.2f kg', true],
            'null format does not mark attribute as unit feature' => [null, false],
            'empty string format does not mark attribute as unit feature' => ['', false],
        ];
    }

    public function testHasUnitIsFalseWhenMmFormatColumnMissing(): void
    {
        // Simulate a non-Verkter Magento install: column doesn't exist, so the SELECT
        // won't include it at all. Confirm has_unit defaults to false for every row.
        $this->connectionMock->method('tableColumnExists')->willReturn(false);
        $this->stubFetchAll([
            [
                'attribute_code' => 'material',
                'attribute_id' => 1,
                'frontend_label' => 'Material',
                'is_searchable' => 1,
                'is_filterable_in_search' => 0,
                'position' => 10,
                'store_label' => null,
            ],
            [
                'attribute_code' => 'weight',
                'attribute_id' => 2,
                'frontend_label' => 'Weight',
                'is_searchable' => 1,
                'is_filterable_in_search' => 1,
                'position' => 20,
                'store_label' => null,
            ],
        ]);

        $result = $this->invokePrivate($this->resolver, 'getFeatureDefinitions', [1]);

        $this->assertCount(2, $result);
        $this->assertFalse($result[0]['has_unit']);
        $this->assertFalse($result[1]['has_unit']);
    }

    public function testHasMmFormatColumnIsCachedAfterFirstCall(): void
    {
        $this->connectionMock->expects($this->once())
            ->method('tableColumnExists')
            ->with('eav_attribute', 'mm_format')
            ->willReturn(true);

        $first = $this->invokePrivate($this->resolver, 'hasMmFormatColumn');
        $second = $this->invokePrivate($this->resolver, 'hasMmFormatColumn');

        $this->assertTrue($first);
        $this->assertTrue($second);
    }

    public function testReturnedShapeIncludesAllFields(): void
    {
        $this->connectionMock->method('tableColumnExists')->willReturn(true);
        $this->stubFetchAll([
            [
                'attribute_code' => 'diameter',
                'attribute_id' => 42,
                'frontend_label' => 'Diameter',
                'mm_format' => '%s mm',
                'is_searchable' => 1,
                'is_filterable_in_search' => 1,
                'position' => 5,
                'store_label' => 'Skersmuo',
            ],
        ]);

        $result = $this->invokePrivate($this->resolver, 'getFeatureDefinitions', [1]);

        $this->assertCount(1, $result);
        $this->assertSame([
            'code' => 'diameter',
            'label' => 'Skersmuo',
            'is_searchable' => true,
            'is_filterable' => true,
            'position' => 5,
            'has_unit' => true,
        ], $result[0]);
    }

    public function testSliderSuffixedAttributeIsMarkedNotFilterable(): void
    {
        $this->connectionMock->method('tableColumnExists')->willReturn(true);
        $this->stubFetchAll([
            [
                'attribute_code' => 'price_slider',
                'attribute_id' => 99,
                'frontend_label' => 'Price',
                'mm_format' => null,
                'is_searchable' => 0,
                'is_filterable_in_search' => 1,
                'position' => 0,
                'store_label' => null,
            ],
        ]);

        $result = $this->invokePrivate($this->resolver, 'getFeatureDefinitions', [1]);

        $this->assertFalse($result[0]['is_filterable']);
    }
}
