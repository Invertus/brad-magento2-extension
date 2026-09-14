<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Model;

use BradSearch\SearchGraphQl\Model\SearchTermFilter;
use PHPUnit\Framework\TestCase;

class SearchTermFilterTest extends TestCase
{
    private SearchTermFilter $subject;

    /**
     * @dataProvider junkTermsProvider
     */
    public function testUrlShapedTermsAreJunk(string $term): void
    {
        $this->assertTrue($this->subject->isJunk($term));
    }

    /**
     * @dataProvider realTermsProvider
     */
    public function testRealSearchTermsAreNotJunk(string $term): void
    {
        $this->assertFalse($this->subject->isJunk($term));
    }

    public static function junkTermsProvider(): array
    {
        return [
            'category path' => ['tsepnye-pily/akkumulyatornye-pily.html'],
            'product slug' => ['triangular-rasp-bosch-1600a03ds1-200-mm.html'],
            'trailing space' => ['index.html '],
            'uppercase extension' => ['Catalog.HTML'],
            'php script' => ['wp-login.php'],
            'image' => ['logo.png'],
            'sitemap' => ['sitemap.xml'],
            'full url' => ['https://example.com/shop'],
            'www host' => ['www.example.com'],
        ];
    }

    public static function realTermsProvider(): array
    {
        return [
            'plain word' => ['Aku'],
            'slash in model number' => ['Karcher 8/1'],
            'dotted model number' => ['Bosch GSR 18V-21.5'],
            'decimal size' => ['drill 6.5 mm'],
            'dash brand' => ['makita-dhp'],
            'empty' => [''],
            'spaces only' => ['   '],
            'html as a word' => ['html book'],
            'www as a word' => ['wwwx'],
        ];
    }

    protected function setUp(): void
    {
        $this->subject = new SearchTermFilter();
    }
}
