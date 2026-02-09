<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\ProductFeatures\Test\Unit\Model\Resolver;

use BradSearch\ProductFeatures\Model\Resolver\Features;
use BradSearch\ProductFeatures\Model\ProductDataLoader;
use Magento\Framework\GraphQl\Query\Resolver\ValueFactory;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Group\CollectionFactory;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\App\ResourceConnection;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;

/**
 * Unit tests for Features resolver formatting methods
 *
 * Tests the core formatting logic including:
 * - mm_format sprintf formatting
 * - Unit and value extraction from strings
 * - Label formatting (snake_case to Title Case)
 * - Unit normalization
 * - Prefix exclusion checking
 */
class FeaturesTest extends TestCase
{
    /**
     * @var Features
     */
    private Features $features;

    /**
     * @var ProductDataLoader|MockObject
     */
    private $productDataLoaderMock;

    /**
     * @var ValueFactory|MockObject
     */
    private $valueFactoryMock;

    /**
     * @var CollectionFactory|MockObject
     */
    private $groupCollectionMock;

    /**
     * @var PriceCurrencyInterface|MockObject
     */
    private $priceCurrencyMock;

    /**
     * @var ResourceConnection|MockObject
     */
    private $resourceConnectionMock;

    /**
     * Set up test dependencies
     */
    protected function setUp(): void
    {
        parent::setUp();

        $this->productDataLoaderMock = $this->getMockBuilder(ProductDataLoader::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->valueFactoryMock = $this->getMockBuilder(ValueFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->groupCollectionMock = $this->getMockBuilder(CollectionFactory::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->priceCurrencyMock = $this->getMockBuilder(PriceCurrencyInterface::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->resourceConnectionMock = $this->getMockBuilder(ResourceConnection::class)
            ->disableOriginalConstructor()
            ->getMock();

        $this->features = new Features(
            $this->productDataLoaderMock,
            $this->valueFactoryMock,
            $this->groupCollectionMock,
            $this->priceCurrencyMock,
            $this->resourceConnectionMock
        );
    }

    /**
     * Test getFormattedValue with mm_format attribute property
     *
     * @dataProvider formattedValueProvider
     */
    public function testGetFormattedValue(
        ?string $mmFormat,
        $value,
        string $expected
    ): void {
        $attributeMock = $this->getMockBuilder(\Magento\Eav\Model\Attribute::class)
            ->disableOriginalConstructor()
            ->getMock();

        $attributeMock->expects($this->once())
            ->method('getData')
            ->with('mm_format')
            ->willReturn($mmFormat);

        $result = $this->invokeMethod($this->features, 'getFormattedValue', [$attributeMock, $value]);

        $this->assertEquals($expected, $result);
    }

    /**
     * Data provider for getFormattedValue tests
     *
     * @return array
     */
    public function formattedValueProvider(): array
    {
        return [
            'with_format_kg' => [
                'mm_format' => '%s kg',
                'value' => '2.5',
                'expected' => '2.5 kg'
            ],
            'with_format_mm' => [
                'mm_format' => '%s mm',
                'value' => '150',
                'expected' => '150 mm'
            ],
            'with_format_watts' => [
                'mm_format' => '%s W',
                'value' => '1500',
                'expected' => '1500 W'
            ],
            'with_format_percentage' => [
                'mm_format' => '%s%%',
                'value' => '95',
                'expected' => '95%'
            ],
            'without_format' => [
                'mm_format' => null,
                'value' => 'Steel',
                'expected' => 'Steel'
            ],
            'without_format_numeric' => [
                'mm_format' => null,
                'value' => '100',
                'expected' => '100'
            ],
            'empty_format' => [
                'mm_format' => '',
                'value' => 'Red',
                'expected' => 'Red'
            ]
        ];
    }

    /**
     * Test formatLabel converts snake_case to Title Case
     *
     * @dataProvider formatLabelProvider
     */
    public function testFormatLabel(string $input, string $expected): void
    {
        $result = $this->invokeMethod($this->features, 'formatLabel', [$input]);
        $this->assertEquals($expected, $result);
    }

    /**
     * Data provider for formatLabel tests
     *
     * @return array
     */
    public function formatLabelProvider(): array
    {
        return [
            'simple_snake_case' => [
                'input' => 'product_name',
                'expected' => 'Product Name'
            ],
            'three_words' => [
                'input' => 'max_power_output',
                'expected' => 'Max Power Output'
            ],
            'single_word' => [
                'input' => 'material',
                'expected' => 'Material'
            ],
            'with_numbers' => [
                'input' => 'voltage_220v',
                'expected' => 'Voltage 220v'
            ],
            'multiple_underscores' => [
                'input' => 'some_long_attribute_name',
                'expected' => 'Some Long Attribute Name'
            ],
            'already_uppercase' => [
                'input' => 'COLOR',
                'expected' => 'COLOR'
            ]
        ];
    }

    /**
     * Test extractUnitAndValue extracts units from various formats
     *
     * @dataProvider extractUnitAndValueProvider
     */
    public function testExtractUnitAndValue(
        string $value,
        ?string $expectedUnit,
        ?float $expectedNumeric,
        bool $expectedHasUnit
    ): void {
        $result = $this->invokeMethod($this->features, 'extractUnitAndValue', [$value]);

        $this->assertEquals($expectedUnit, $result['unit'], "Unit mismatch for value: $value");
        $this->assertEquals($expectedNumeric, $result['numeric_value'], "Numeric value mismatch for value: $value");
        $this->assertEquals($expectedHasUnit, $result['has_unit'], "Has unit flag mismatch for value: $value");
    }

    /**
     * Data provider for extractUnitAndValue tests
     *
     * @return array
     */
    public function extractUnitAndValueProvider(): array
    {
        return [
            // Weight units
            'weight_kg_space' => ['2.5 kg', 'kg', 2.5, true],
            'weight_kg_no_space' => ['3kg', 'kg', 3.0, true],
            'weight_g' => ['500 g', 'g', 500.0, true],
            'weight_comma_decimal' => ['2,5 kg', 'kg', 2.5, true],

            // Length units
            'length_mm' => ['150 mm', 'mm', 150.0, true],
            'length_cm' => ['15 cm', 'cm', 15.0, true],
            'length_m' => ['1.5 m', 'm', 1.5, true],
            'length_m_in_text' => ['The length is 2 m long', 'm', 2.0, true],

            // Inches (various notations)
            'inches_double_quote' => ['10"', '"', 10.0, true],
            'inches_unicode_quote' => ['10″', '"', 10.0, true],
            'inches_single_quote' => ["10'", '"', 10.0, true],
            'inches_with_space' => ['10 "', '"', 10.0, true],

            // Electrical units
            'power_kW' => ['2.5 kW', 'kW', 2.5, true],
            'power_W' => ['1500 W', 'W', 1500.0, true],
            'voltage_V' => ['220 V', 'V', 220.0, true],
            'current_A' => ['15 A', 'A', 15.0, true],
            'frequency_kHz' => ['50 kHz', 'kHz', 50.0, true],
            'frequency_Hz' => ['60 Hz', 'Hz', 60.0, true],

            // Torque
            'torque_Nm' => ['50 Nm', 'Nm', 50.0, true],
            'torque_Nm_no_space' => ['100Nm', 'Nm', 100.0, true],

            // Pressure
            'pressure_bar' => ['8 bar', 'bar', 8.0, true],
            'pressure_PSI' => ['120 PSI', 'PSI', 120.0, true],

            // Speed/RPM
            'speed_rpm' => ['3000 rpm', 'rpm', 3000.0, true],
            'speed_rpm_comma' => ['3,000 rpm', 'rpm', 3.0, true], // Comma treated as decimal separator

            // Sound
            'sound_dB' => ['85 dB', 'dB', 85.0, true],
            'sound_dB_decimal' => ['85.5 dB', 'dB', 85.5, true],

            // Volume
            'volume_ml' => ['500 ml', 'ml', 500.0, true],
            'volume_l' => ['2 l', 'l', 2.0, true],
            'volume_L_uppercase' => ['2 L', 'l', 2.0, true],

            // Angle
            'angle_degrees' => ['45°', '°', 45.0, true],
            'angle_degrees_space' => ['90 °', '°', 90.0, true],

            // No unit
            'no_unit_text' => ['Steel', null, null, false],
            'no_unit_number' => ['12345', null, null, false],
            'no_unit_color' => ['Red', null, null, false],

            // Complex values with text
            'with_prefix_text' => ['Max 2.5 kg', 'kg', 2.5, true],
            'with_suffix_text' => ['2.5 kg maximum', 'kg', 2.5, true],
        ];
    }

    /**
     * Test normalizeUnit standardizes unit formats
     *
     * @dataProvider normalizeUnitProvider
     */
    public function testNormalizeUnit(string $input, string $expected): void
    {
        $result = $this->invokeMethod($this->features, 'normalizeUnit', [$input]);
        $this->assertEquals($expected, $result);
    }

    /**
     * Data provider for normalizeUnit tests
     *
     * @return array
     */
    public function normalizeUnitProvider(): array
    {
        return [
            'unicode_quote_to_double' => ['″', '"'],
            'single_quote_to_double' => ["'", '"'],
            'curly_quote_to_double' => ["'", '"'],
            'uppercase_L_to_lowercase' => ['L', 'l'],
            'unchanged_kg' => ['kg', 'kg'],
            'unchanged_mm' => ['mm', 'mm'],
            'unchanged_W' => ['W', 'W'],
        ];
    }

    /**
     * Test getUnitFromPattern extracts unit from pattern
     *
     * @dataProvider getUnitFromPatternProvider
     */
    public function testGetUnitFromPattern(string $pattern, string $match, string $expected): void
    {
        $result = $this->invokeMethod($this->features, 'getUnitFromPattern', [$pattern, $match]);
        $this->assertEquals($expected, $result);
    }

    /**
     * Data provider for getUnitFromPattern tests
     *
     * @return array
     */
    public function getUnitFromPatternProvider(): array
    {
        return [
            'degrees_pattern' => [
                'pattern' => '/(\d+(?:[,\.]\d+)?)\s*°/i',
                'match' => '45°',
                'expected' => '°'
            ],
            'inches_pattern' => [
                'pattern' => '/(\d+(?:[,\.]\d+)?)\s*["″\'\']/i',
                'match' => '10"',
                'expected' => '"'
            ],
            'meters_pattern' => [
                'pattern' => '/(\d+(?:[,\.]\d+)?)\s*m\b(?!\w)/i',
                'match' => '2 m',
                'expected' => 'm'
            ],
            'unknown_pattern' => [
                'pattern' => '/(\d+(?:[,\.]\d+)?)\s*(kg)\b/i',
                'match' => '5kg',
                'expected' => ''
            ]
        ];
    }

    /**
     * Test hasExcludedPrefix identifies excluded attribute prefixes
     *
     * @dataProvider hasExcludedPrefixProvider
     */
    public function testHasExcludedPrefix(string $attributeCode, bool $expected): void
    {
        $result = $this->invokeMethod($this->features, 'hasExcludedPrefix', [$attributeCode]);
        $this->assertEquals($expected, $result);
    }

    /**
     * Data provider for hasExcludedPrefix tests
     *
     * @return array
     */
    public function hasExcludedPrefixProvider(): array
    {
        return [
            'price_ee' => ['price_ee', true],
            'price_lt' => ['price_lt', true],
            'price_lv' => ['price_lv', true],
            'price_dk' => ['price_dk', true],
            'price_only' => ['price', false], // 'price' doesn't match 'price_' prefix
            'not_excluded_material' => ['material', false],
            'not_excluded_weight' => ['weight', false],
            'not_excluded_color' => ['color', false],
            'price_in_middle' => ['special_price_ee', false], // prefix check only
        ];
    }

    /**
     * Test extractUnitAndValue handles edge cases
     */
    public function testExtractUnitAndValueEdgeCases(): void
    {
        // Empty string
        $result = $this->invokeMethod($this->features, 'extractUnitAndValue', ['']);
        $this->assertNull($result['unit']);
        $this->assertNull($result['numeric_value']);
        $this->assertFalse($result['has_unit']);

        // Only unit without number
        $result = $this->invokeMethod($this->features, 'extractUnitAndValue', ['kg']);
        $this->assertNull($result['unit']);
        $this->assertNull($result['numeric_value']);
        $this->assertFalse($result['has_unit']);

        // Multiple units (should match first pattern found - mm comes before kg in pattern order)
        $result = $this->invokeMethod($this->features, 'extractUnitAndValue', ['2.5 kg and 150 mm']);
        $this->assertEquals('mm', $result['unit']);
        $this->assertEquals(150.0, $result['numeric_value']);
        $this->assertTrue($result['has_unit']);
    }

    /**
     * Test formatLabel edge cases
     */
    public function testFormatLabelEdgeCases(): void
    {
        // Empty string
        $result = $this->invokeMethod($this->features, 'formatLabel', ['']);
        $this->assertEquals('', $result);

        // No underscores
        $result = $this->invokeMethod($this->features, 'formatLabel', ['material']);
        $this->assertEquals('Material', $result);

        // Starting with underscore
        $result = $this->invokeMethod($this->features, 'formatLabel', ['_hidden']);
        $this->assertEquals(' Hidden', $result);

        // Ending with underscore
        $result = $this->invokeMethod($this->features, 'formatLabel', ['attribute_']);
        $this->assertEquals('Attribute ', $result);
    }

    /**
     * Test getFormattedValue with complex sprintf formats
     */
    public function testGetFormattedValueComplexFormats(): void
    {
        $attributeMock = $this->getMockBuilder(\Magento\Eav\Model\Attribute::class)
            ->disableOriginalConstructor()
            ->getMock();

        // Test with decimal format
        $attributeMock->expects($this->once())
            ->method('getData')
            ->with('mm_format')
            ->willReturn('%.2f kg');

        $result = $this->invokeMethod($this->features, 'getFormattedValue', [$attributeMock, '2.5']);
        $this->assertEquals('2.50 kg', $result);
    }

    /**
     * Helper method to invoke private/protected methods
     *
     * @param object $object
     * @param string $methodName
     * @param array $parameters
     * @return mixed
     */
    private function invokeMethod($object, string $methodName, array $parameters = [])
    {
        $reflection = new \ReflectionClass(get_class($object));
        $method = $reflection->getMethod($methodName);
        $method->setAccessible(true);

        return $method->invokeArgs($object, $parameters);
    }
}
