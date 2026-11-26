<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\Test\Integration;

/**
 * The bradProducts answer to the exact query brad-app sends keeps the shape of the shared contract
 * fixture (fixtures/contract/products.json, a copy of brad-search-php-sdk
 * tests/fixtures/platform-contracts/magento, which brad-app's Magento adapter tests read too; CI diffs
 * the copy against the SDK). bin/seed-contract-product.php fills BRAD-IT-ALPHA with a category,
 * descriptions and a custom attribute, so every field has a value.
 *
 * Only the shape is compared: every key of the fixture must be in the answer with the same JSON type;
 * list elements are held to the union of the fixture's elements. Null is accepted only for a key the
 * fixture has null somewhere, an empty list only for a key the fixture has empty somewhere. Extra keys
 * are allowed: brad-app ignores new fields, but a removed, renamed or retyped one breaks it.
 *
 * The health check brad-app sends is Magento core's storeConfig query, not extension code, so
 * fixtures/contract/health.json is only kept in step with the SDK.
 */
class BradProductsContractTest extends IntegrationTestCase
{
    /**
     * brad-app's products query: MagentoApi::getProducts() sends brad-search-php-sdk's
     * MagentoProductQuery::getDefaultQuery() (SDK v4.23.0) with `skipped_count` added after
     * `total_count` (MagentoApi::withSkippedCount()). Copy it again from there when brad-app changes it.
     */
    private const BRAD_APP_PRODUCTS_QUERY = <<<'GRAPHQL'
query GetProducts($filter: BradProductFilterInput, $pageSize: Int, $currentPage: Int) {
    bradProducts(filter: $filter, pageSize: $pageSize, currentPage: $currentPage) {
        total_count
        skipped_count
        page_info {
            current_page
            page_size
            total_pages
        }
        items {
            id
            sku
            name
            full_url
            is_in_stock
            allows_backorders
            short_description { html }
            description { html }
            attributes {
                attribute_id
                code
                label
                value
                formatted
                position
                is_searchable
                is_filterable
                is_visible
                unit
                numeric_value
                has_unit
            }
            image_optimized
            calculated_price {
                minimum_price {
                    regular_price { value currency }
                    final_price { value currency }
                    final_price_excl_tax { value currency }
                }
            }
            categories {
                id
                name
                url_path
                level
                path
            }
            stock_status
            sort_popularity
            sort_popularity_sales
        }
    }
}
GRAPHQL;

    /** @var string[] */
    private array $nullableKeys = [];

    /** @var string[] */
    private array $emptyKeys = [];

    public function testTheAnswerToBradAppsProductsQueryMatchesTheContract(): void
    {
        $response = $this->graphQl(
            self::BRAD_APP_PRODUCTS_QUERY,
            ['filter' => ['entity_id' => ['in' => [(string)$this->productId('BRAD-IT-ALPHA')]]], 'pageSize' => 50, 'currentPage' => 0],
            ['X-BradSearch-Api-Key' => self::PRIVATE_API_KEY, 'Store' => 'default']
        );

        $this->assertNoGraphQlErrors($response);
        $this->assertSame(['BRAD-IT-ALPHA'], array_column($response['data']['bradProducts']['items'], 'sku'));

        $fixture = json_decode((string)file_get_contents(dirname(__DIR__) . '/fixtures/contract/products.json'), true);
        $this->nullableKeys = $this->keysHolding($fixture, null);
        $this->emptyKeys = $this->keysHolding($fixture, []);
        $this->assertMatchesShape($fixture, $response);
    }

    /**
     * @param mixed $expected
     * @param mixed $actual
     */
    private function assertMatchesShape($expected, $actual, string $path = '$'): void
    {
        $key = (string)preg_replace('/^.*\./', '', $path);

        if ($actual === null) {
            $this->assertTrue($expected === null || in_array($key, $this->nullableKeys, true), "$path is null");

            return;
        }
        if ($expected === null) {
            return;
        }
        if ($actual === [] && is_array($expected)) {
            $this->assertTrue($expected === [] || in_array($key, $this->emptyKeys, true), "$path is empty, and the fixture never has it empty");

            return;
        }

        $this->assertSame($this->jsonType($expected), $this->jsonType($actual), "$path changed its type");
        if (!is_array($expected) || $expected === []) {
            return;
        }

        if (array_is_list($expected)) {
            $template = array_reduce($expected, fn ($carry, $element) => $this->union($carry, $element));
            foreach ($actual as $index => $element) {
                $this->assertMatchesShape($template, $element, "$path" . "[$index]");
            }

            return;
        }

        foreach ($expected as $name => $value) {
            $this->assertArrayHasKey($name, $actual, "$path.$name is missing");
            $this->assertMatchesShape($value, $actual[$name], "$path.$name");
        }
    }

    /**
     * Two fixture values as one template: objects key by key, a non-null value over a null one.
     *
     * @param mixed $a
     * @param mixed $b
     * @return mixed
     */
    private function union($a, $b)
    {
        if ($a === null || $a === []) {
            return $b;
        }
        if (!is_array($a) || !is_array($b) || $b === []) {
            return $a;
        }
        if (array_is_list($a)) {
            return array_merge($a, $b);
        }
        foreach ($b as $name => $value) {
            $a[$name] = array_key_exists($name, $a) ? $this->union($a[$name], $value) : $value;
        }

        return $a;
    }

    /**
     * @param mixed $document
     * @param mixed $needle null or []
     * @return string[] keys that hold $needle somewhere in the document
     */
    private function keysHolding($document, $needle, string $key = ''): array
    {
        if ($document === $needle) {
            return [$key];
        }
        if (!is_array($document)) {
            return [];
        }
        $keys = [];
        foreach ($document as $name => $value) {
            $keys = array_merge($keys, $this->keysHolding($value, $needle, is_int($name) ? $key : (string)$name));
        }

        return $keys;
    }

    /**
     * @param mixed $value
     */
    private function jsonType($value): string
    {
        if (is_array($value)) {
            return array_is_list($value) ? 'list' : 'object';
        }
        if (is_int($value) || is_float($value)) {
            return 'number';
        }

        return gettype($value);
    }
}
