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
            'query string after html' => ['tsepnye-pily/akkumulyatornye-pily.html?p=2'],
            'fragment after html' => ['catalog.html#top'],
            'path after html' => ['category.html/page-2'],
            'php script' => ['wp-login.php'],
            'image' => ['logo.png'],
            'sitemap' => ['sitemap.xml'],
            'full url' => ['https://example.com/shop'],
            'www host' => ['www.example.com'],
            'magento search route' => ['catalogsearch/result/'],
            'magento compare route' => ['catalog/product_compare/add/product/123/'],
            'magento customer route' => ['customer/account/login/'],
            'magento sales route' => ['sales/order/view/order_id/1/'],
            'dead erpext module route' => ['erpext/inventory/deliverystatus/product_id/1/'],
            'dead autorelated module route' => ['adeoweb_autorelated/ajax/loadBlock/product_id/1/'],
            'env file probe' => ['.env'],
            'bare wp probe' => ['wp/'],
            'wp-json probe' => ['wp/wp-json/'],
            'proc environ probe' => ['api/proc/self/environ'],
            'aws credentials probe' => ['.aws/credentials'],
            'laravel log probe' => ['storage/logs/laravel.log'],
            'site root probe' => ['site/'],
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
            'js in product name' => ['Node.js'],
            'css in product name' => ['bootstrap.css'],
            'txt file name' => ['readme.txt'],
            'pz model' => ['Pz1/sl'],
            'sl model' => ['Sl/pz2'],
            'fraction' => ['1/2'],
            'fraction with brand' => ['3/8 hazet'],
            'product code with slashes' => ['A-ULTRA/SE24/10WW'],
            'product code with dash prefix' => ['K-F431/8'],
            'year slash number' => ['2027/3'],
            'metric sizes' => ['m14/m16'],
            'brand with model slash' => ['Kärcher NT 22/1 Ap Te L'],
            'hyphenated category with brand' => ['zoliapjoves-vejapjoves/stiga'],
            'wp product code' => ['WP/8-3/H11'],
            'lowercase wp product code' => ['wp/12-3/h11'],
        ];
    }

    protected function setUp(): void
    {
        $this->subject = new SearchTermFilter();
    }
}
