<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Model;

use BradSearch\SearchGraphQl\Model\PopularityExtractor;
use PHPUnit\Framework\TestCase;

class PopularityExtractorTest extends TestCase
{
    private PopularityExtractor $extractor;

    protected function setUp(): void
    {
        $this->extractor = new PopularityExtractor();
    }

    public function testValidFormatOutOfStock(): void
    {
        $result = $this->extractor->extract('O999N999');

        $this->assertTrue($result['is_valid']);
        $this->assertEquals('O999N999', $result['sort_popularity']);
        $this->assertEquals(999, $result['sort_popularity_sales']);
    }

    public function testValidFormatInStock(): void
    {
        $result = $this->extractor->extract('I492I000');

        $this->assertTrue($result['is_valid']);
        $this->assertEquals('I492I000', $result['sort_popularity']);
        $this->assertEquals(492, $result['sort_popularity_sales']);
    }

    public function testValidFormatWithNulAmazon(): void
    {
        $result = $this->extractor->extract('O999NNUL');

        $this->assertTrue($result['is_valid']);
        $this->assertEquals('O999NNUL', $result['sort_popularity']);
        $this->assertEquals(999, $result['sort_popularity_sales']);
    }

    public function testMalformedFormatMissingStockPrefix(): void
    {
        // This is the actual malformed data: 999N999 (7 chars instead of 8)
        $result = $this->extractor->extract('999N999');

        $this->assertFalse($result['is_valid']);
        $this->assertEquals('O999N999', $result['sort_popularity']);
        $this->assertEquals(999, $result['sort_popularity_sales']);
    }

    public function testMalformedFormatTooShort(): void
    {
        $result = $this->extractor->extract('O999');

        $this->assertFalse($result['is_valid']);
        $this->assertEquals('O999N999', $result['sort_popularity']);
        $this->assertEquals(999, $result['sort_popularity_sales']);
    }

    public function testMalformedFormatTooLong(): void
    {
        $result = $this->extractor->extract('O999N9999');

        $this->assertFalse($result['is_valid']);
        $this->assertEquals('O999N999', $result['sort_popularity']);
        $this->assertEquals(999, $result['sort_popularity_sales']);
    }

    public function testMalformedFormatInvalidStockPrefix(): void
    {
        $result = $this->extractor->extract('X999N999');

        $this->assertFalse($result['is_valid']);
        $this->assertEquals('O999N999', $result['sort_popularity']);
        $this->assertEquals(999, $result['sort_popularity_sales']);
    }

    public function testMalformedFormatNonNumericSold(): void
    {
        $result = $this->extractor->extract('OAAAN999');

        $this->assertFalse($result['is_valid']);
        $this->assertEquals('O999N999', $result['sort_popularity']);
        $this->assertEquals(999, $result['sort_popularity_sales']);
    }

    public function testMalformedFormatInvalidImagePrefix(): void
    {
        $result = $this->extractor->extract('O999X999');

        $this->assertFalse($result['is_valid']);
        $this->assertEquals('O999N999', $result['sort_popularity']);
        $this->assertEquals(999, $result['sort_popularity_sales']);
    }

    public function testMalformedFormatInvalidAmazon(): void
    {
        $result = $this->extractor->extract('O999NABC');

        $this->assertFalse($result['is_valid']);
        $this->assertEquals('O999N999', $result['sort_popularity']);
        $this->assertEquals(999, $result['sort_popularity_sales']);
    }

    public function testNullValueUsesDefault(): void
    {
        $result = $this->extractor->extract(null);

        $this->assertTrue($result['is_valid']);
        $this->assertEquals('O999N999', $result['sort_popularity']);
        $this->assertEquals(999, $result['sort_popularity_sales']);
    }

    public function testGetSortPopularityValid(): void
    {
        $this->assertEquals('I492I000', $this->extractor->getSortPopularity('I492I000'));
    }

    public function testGetSortPopularityInvalid(): void
    {
        $this->assertEquals('O999N999', $this->extractor->getSortPopularity('999N999'));
    }

    public function testGetSortPopularityNull(): void
    {
        $this->assertEquals('O999N999', $this->extractor->getSortPopularity(null));
    }

    public function testGetSortPopularitySalesValid(): void
    {
        $this->assertEquals(492, $this->extractor->getSortPopularitySales('I492I000'));
    }

    public function testGetSortPopularitySalesInvalid(): void
    {
        $this->assertEquals(999, $this->extractor->getSortPopularitySales('999N999'));
    }

    public function testGetSortPopularitySalesNull(): void
    {
        $this->assertEquals(999, $this->extractor->getSortPopularitySales(null));
    }

    public function testVariousSalesValues(): void
    {
        // Test different sales values (reversed: 000 = most sales, 999 = least sales)
        $this->assertEquals(0, $this->extractor->getSortPopularitySales('I000I000'));
        $this->assertEquals(123, $this->extractor->getSortPopularitySales('O123N456'));
        $this->assertEquals(500, $this->extractor->getSortPopularitySales('I500INUL'));
    }
}
