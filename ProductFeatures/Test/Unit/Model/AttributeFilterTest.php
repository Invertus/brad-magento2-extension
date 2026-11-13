<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\ProductFeatures\Test\Unit\Model;

use BradSearch\ProductFeatures\Model\AttributeFilter;
use PHPUnit\Framework\TestCase;

/**
 * Unit tests for AttributeFilter
 *
 * Tests:
 * - Label formatting (snake_case to Title Case)
 * - Prefix exclusion checking
 */
class AttributeFilterTest extends TestCase
{
    /**
     * Test formatLabel converts snake_case to Title Case
     *
     * @dataProvider formatLabelProvider
     */
    public function testFormatLabel(string $input, string $expected): void
    {
        $this->assertEquals($expected, AttributeFilter::formatLabel($input));
    }

    /**
     * Data provider for formatLabel tests
     *
     * @return array
     */
    public static function formatLabelProvider(): array
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
     * Test formatLabel edge cases
     */
    public function testFormatLabelEdgeCases(): void
    {
        // Empty string
        $this->assertEquals('', AttributeFilter::formatLabel(''));

        // No underscores
        $this->assertEquals('Material', AttributeFilter::formatLabel('material'));

        // Starting with underscore
        $this->assertEquals(' Hidden', AttributeFilter::formatLabel('_hidden'));

        // Ending with underscore
        $this->assertEquals('Attribute ', AttributeFilter::formatLabel('attribute_'));
    }

    /**
     * Test the excluded prefix rule, for codes that are not in the exact-name list
     *
     * @dataProvider excludedPrefixProvider
     */
    public function testExcludedPrefix(string $attributeCode, bool $expected): void
    {
        $this->assertNotContains($attributeCode, AttributeFilter::EXCLUDED_ATTRIBUTES);
        $this->assertEquals($expected, AttributeFilter::isExcluded($attributeCode));
    }

    /**
     * Data provider for excluded prefix tests
     *
     * @return array
     */
    public static function excludedPrefixProvider(): array
    {
        return [
            'price_ee' => ['price_ee', true],
            'price_lt' => ['price_lt', true],
            'price_lv' => ['price_lv', true],
            'price_dk' => ['price_dk', true],
            'not_excluded_material' => ['material', false],
            'not_excluded_color' => ['color', false],
            'price_in_middle' => ['special_price_ee', false], // prefix check only
        ];
    }

    /**
     * 'price' and 'weight' do not match the 'price_' prefix. They are excluded
     * by the exact-name list instead.
     *
     * @dataProvider prefixDoesNotMatchProvider
     */
    public function testExcludedByNameNotByPrefix(string $attributeCode): void
    {
        foreach (AttributeFilter::EXCLUDED_ATTRIBUTE_PREFIXES as $prefix) {
            $this->assertStringStartsNotWith($prefix, $attributeCode);
        }
        $this->assertContains($attributeCode, AttributeFilter::EXCLUDED_ATTRIBUTES);
        $this->assertTrue(AttributeFilter::isExcluded($attributeCode));
    }

    /**
     * Data provider for codes the prefix rule does not match
     *
     * @return array
     */
    public static function prefixDoesNotMatchProvider(): array
    {
        return [
            'price_only' => ['price'], // 'price' doesn't match 'price_' prefix
            'weight' => ['weight'],
        ];
    }
}
