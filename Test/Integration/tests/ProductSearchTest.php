<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\Test\Integration;

/**
 * A storefront search (the products query in a ProductSearch operation) is answered by BradSearch: the
 * engine picks and orders the products, Magento loads them. Other products queries stay with Magento.
 */
class ProductSearchTest extends IntegrationTestCase
{
    private const SEARCH = 'query %s($search: String!) {
        products(search: $search, pageSize: 10) {
            total_count
            items { sku }
            bradsearch_search { correlation_id }
        }
    }';

    private const TERM = 'integration';

    protected function setUp(): void
    {
        $this->resetBradSearch();
    }

    public function testProductSearchIsAnsweredByBradSearch(): void
    {
        // An order and a subset OpenSearch would not return for this term, so the result can only come from the engine.
        $this->stubBradSearch([
            'request' => [
                'method' => 'GET',
                'urlPath' => '/v1/search',
                'queryParameters' => ['q' => ['equalTo' => self::TERM]],
            ],
            'response' => [
                'status' => 200,
                'headers' => ['Content-Type' => 'application/json', 'X-Request-Correlation-ID' => 'it-correlation-1'],
                'jsonBody' => [
                    'total' => 2,
                    'documents' => [
                        ['id' => (string)$this->productId('BRAD-IT-GAMMA')],
                        ['id' => (string)$this->productId('BRAD-IT-ALPHA')],
                    ],
                ],
            ],
        ]);

        $response = $this->graphQl(sprintf(self::SEARCH, 'ProductSearch'), ['search' => self::TERM]);

        $this->assertNoGraphQlErrors($response);
        $products = $response['data']['products'];
        $this->assertSame(['BRAD-IT-GAMMA', 'BRAD-IT-ALPHA'], array_column($products['items'], 'sku'));
        $this->assertSame(2, $products['total_count']);
        $this->assertSame('it-correlation-1', $products['bradsearch_search']['correlation_id']);

        $requests = $this->bradSearchRequests(['method' => 'GET', 'urlPath' => '/v1/search']);
        $this->assertCount(1, $requests);
        $this->assertSame(self::TERM, $requests[0]['queryParams']['q']['values'][0]);
        $this->assertSame(self::SEARCH_TOKEN, $requests[0]['queryParams']['token']['values'][0]);
    }

    public function testOtherOperationsAreAnsweredByMagento(): void
    {
        $response = $this->graphQl(sprintf(self::SEARCH, 'CategorySearch'), ['search' => self::TERM]);

        $this->assertNoGraphQlErrors($response);
        $skus = array_column($response['data']['products']['items'], 'sku');
        sort($skus);
        $this->assertSame(self::SEEDED_SKUS, $skus, 'Magento search (OpenSearch) finds every seeded product');
        $this->assertNull($response['data']['products']['bradsearch_search']);
        $this->assertSame([], $this->bradSearchRequests(['method' => 'ANY', 'urlPattern' => '.*']));
    }
}
