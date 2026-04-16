<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Model\Api;

use BradSearch\SearchGraphQl\Model\Api\Client;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ClientTest extends TestCase
{
    private Client $subject;
    private Curl&MockObject $curlMock;
    private ScopeConfigInterface&MockObject $scopeConfigMock;
    private StoreManagerInterface&MockObject $storeManagerMock;
    private LoggerInterface&MockObject $loggerMock;

    /**
     * Test successful API search request
     */
    public function testSearchSuccess(): void
    {
        $searchTerm = 'laptop';
        $pageSize = 20;
        $currentPage = 1;
        $apiResponse = ['documents' => [['id' => '123']], 'total' => 100];

        // Mock configuration
        $this->setupConfigMocks('https://api.example.com/search', 'test-token-123');

        // Mock successful curl response
        $this->curlMock->expects($this->exactly(2))->method('addHeader'); // Accept and Accept-Language
        $this->curlMock->expects($this->exactly(2))->method('setOption'); // TIMEOUT and CONNECTTIMEOUT
        $this->curlMock->expects($this->once())->method('get');
        $this->curlMock->expects($this->once())->method('getStatus')->willReturn(200);
        $this->curlMock->expects($this->once())->method('getBody')->willReturn(json_encode($apiResponse));

        $result = $this->subject->search($searchTerm, $pageSize, $currentPage);

        $this->assertSame($apiResponse, $result);
    }

    /**
     * Test API request with non-200 status code
     */
    public function testSearchWithErrorStatusCode(): void
    {
        $this->setupConfigMocks('https://api.example.com/search', 'test-token');

        $this->curlMock->method('getStatus')->willReturn(500);
        $this->curlMock->method('getBody')->willReturn('Internal Server Error');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('BradSearch API returned status code: 500');

        $this->subject->search('test');
    }

    /**
     * Test API request with invalid JSON response
     */
    public function testSearchWithInvalidJson(): void
    {
        $this->setupConfigMocks('https://api.example.com/search', 'test-token');

        $this->curlMock->method('getStatus')->willReturn(200);
        $this->curlMock->method('getBody')->willReturn('Invalid JSON {{{');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Failed to decode BradSearch API response/');

        $this->subject->search('test');
    }

    /**
     * Test API request with missing configuration
     */
    public function testSearchWithMissingApiUrl(): void
    {
        $this->setupConfigMocks('', 'test-token');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('BradSearch API URL or token not configured');

        $this->subject->search('test');
    }

    /**
     * Test API request with missing token
     */
    public function testSearchWithMissingToken(): void
    {
        $this->setupConfigMocks('https://api.example.com/search', '');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('BradSearch API URL or token not configured');

        $this->subject->search('test');
    }

    /**
     * Test search with filters and sort parameters
     */
    public function testSearchWithFiltersAndSort(): void
    {
        $filters = [
            'color' => ['eq' => 'red'],
            'price' => ['from' => '100', 'to' => '500'],
            'size' => ['in' => ['M', 'L']],
        ];
        $sort = ['price' => 'ASC'];

        $this->setupConfigMocks('https://api.example.com/search', 'token');

        $this->curlMock->method('getStatus')->willReturn(200);
        $this->curlMock->method('getBody')->willReturn(json_encode(['documents' => [], 'total' => 0]));

        // Verify that get() is called with URL containing filter parameters
        $this->curlMock->expects($this->once())
            ->method('get')
            ->with($this->callback(function ($url) {
                // URL encode the brackets for matching
                // Check that filters are properly formatted in URL
                $hasColor = strpos($url, 'color') !== false && strpos($url, 'red') !== false;
                $hasPriceFrom = strpos($url, 'price') !== false && strpos($url, '100') !== false;
                $hasPriceTo = strpos($url, 'price') !== false && strpos($url, '500') !== false;
                $hasSort = strpos($url, 'sortby=price') !== false;
                $hasOrder = strpos($url, 'order=asc') !== false;

                return $hasColor && $hasPriceFrom && $hasPriceTo && $hasSort && $hasOrder;
            }));

        $this->subject->search('test', 18, 1, $filters, $sort);
    }

    /**
     * Test fetchFacets success
     */
    public function testFetchFacetsSuccess(): void
    {
        $searchTerm = 'laptop';
        $facetsResponse = ['facets' => [['name' => 'color', 'values' => ['red', 'blue']]]];

        // Mock facets API URL
        $this->scopeConfigMock
            ->method('getValue')
            ->willReturnMap([
                ['bradsearch_search/general/facets_api_url', 'store', 1, 'https://api.example.com/facets'],
                ['bradsearch_search/general/api_key', 'store', 1, 'test-token'],
            ]);

        $this->curlMock->method('getStatus')->willReturn(200);
        $this->curlMock->method('getBody')->willReturn(json_encode($facetsResponse));

        $result = $this->subject->fetchFacets($searchTerm);

        $this->assertSame($facetsResponse, $result);
    }

    /**
     * Test fetchFacets with v1 URL sends filters in attributes[code][idx] format
     */
    public function testFetchFacetsWithV1Filters(): void
    {
        $filters = [
            'brand' => ['eq' => 'Bosch'],
            'price' => ['from' => '50', 'to' => '200'],
            'color' => ['in' => ['Red', 'Blue']],
            'category_id' => ['eq' => '42'], // excluded filter
        ];

        $this->scopeConfigMock
            ->method('getValue')
            ->willReturnMap([
                ['bradsearch_search/general/facets_api_url', 'store', 1, 'https://api.example.com/api/v1/verkter-lt/aggregations'],
                ['bradsearch_search/general/api_key', 'store', 1, 'test-token'],
                ['general/locale/code', 'store', 1, 'lt-LT'],
            ]);

        $this->curlMock->method('getStatus')->willReturn(200);
        $this->curlMock->method('getBody')->willReturn(json_encode(['facets' => ['attributes' => []]]));

        $this->curlMock->expects($this->once())
            ->method('get')
            ->with($this->callback(function ($url) {
                // V1 format: attributes[code][idx]
                $hasBrand = str_contains($url, urlencode('attributes[brand][0]') . '=Bosch');
                $hasPriceFrom = str_contains($url, urlencode('attributes[price][from]') . '=50');
                $hasPriceTo = str_contains($url, urlencode('attributes[price][to]') . '=200');
                $hasColor0 = str_contains($url, urlencode('attributes[color][0]') . '=Red');
                $hasColor1 = str_contains($url, urlencode('attributes[color][1]') . '=Blue');
                // Excluded filter should NOT be present
                $noCategoryId = !str_contains($url, 'category_id');

                return $hasBrand && $hasPriceFrom && $hasPriceTo && $hasColor0 && $hasColor1 && $noCategoryId;
            }));

        $this->subject->fetchFacets('drill', $filters);
    }

    /**
     * Test fetchFacets with v2 URL sends filters in flat format
     */
    public function testFetchFacetsWithV2Filters(): void
    {
        $filters = [
            'brand' => ['eq' => 'Bosch'],
            'price' => ['from' => '50', 'to' => '200'],
            'color' => ['in' => ['Red', 'Blue']],
            'show_out_of_stock' => ['eq' => '1'], // excluded filter
        ];

        $this->scopeConfigMock
            ->method('getValue')
            ->willReturnMap([
                ['bradsearch_search/general/facets_api_url', 'store', 1, 'https://api.example.com/api/v2/aggregations'],
                ['bradsearch_search/general/api_key', 'store', 1, 'test-token'],
                ['general/locale/code', 'store', 1, 'lt-LT'],
            ]);

        $this->curlMock->method('getStatus')->willReturn(200);
        $this->curlMock->method('getBody')->willReturn(json_encode(['facets' => []]));

        $this->curlMock->expects($this->once())
            ->method('get')
            ->with($this->callback(function ($url) {
                // V2 format: flat params
                $hasBrand = str_contains($url, urlencode('brand[0]') . '=Bosch');
                $hasPriceMin = str_contains($url, 'price_min=50');
                $hasPriceMax = str_contains($url, 'price_max=200');
                $hasColor0 = str_contains($url, urlencode('color[0]') . '=Red');
                $hasColor1 = str_contains($url, urlencode('color[1]') . '=Blue');
                // Excluded filter should NOT be present
                $noOutOfStock = !str_contains($url, 'show_out_of_stock');
                // Should NOT use attributes[] wrapper
                $noAttributes = !str_contains($url, 'attributes');

                return $hasBrand && $hasPriceMin && $hasPriceMax && $hasColor0 && $hasColor1 && $noOutOfStock && $noAttributes;
            }));

        $this->subject->fetchFacets('drill', $filters);
    }

    /**
     * Test fetchFacets without filters sends only token and q
     */
    public function testFetchFacetsWithoutFilters(): void
    {
        $this->scopeConfigMock
            ->method('getValue')
            ->willReturnMap([
                ['bradsearch_search/general/facets_api_url', 'store', 1, 'https://api.example.com/api/v2/aggregations'],
                ['bradsearch_search/general/api_key', 'store', 1, 'test-token'],
                ['general/locale/code', 'store', 1, 'en-US'],
            ]);

        $this->curlMock->method('getStatus')->willReturn(200);
        $this->curlMock->method('getBody')->willReturn(json_encode(['facets' => []]));

        $this->curlMock->expects($this->once())
            ->method('get')
            ->with($this->callback(function ($url) {
                $parsed = parse_url($url);
                parse_str($parsed['query'] ?? '', $params);
                // Should only have token and q
                return count($params) === 2
                    && isset($params['token'])
                    && isset($params['q'])
                    && $params['q'] === 'laptop';
            }));

        $this->subject->fetchFacets('laptop');
    }

    /**
     * Test fetchFacets with missing configuration
     */
    public function testFetchFacetsWithMissingConfig(): void
    {
        $this->scopeConfigMock
            ->method('getValue')
            ->willReturn('');

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('BradSearch Facets API URL or token not configured');

        $this->subject->fetchFacets('test');
    }

    /**
     * Helper to setup config mocks
     */
    private function setupConfigMocks(string $apiUrl, string $token): void
    {
        $this->scopeConfigMock
            ->method('getValue')
            ->willReturnMap([
                ['bradsearch_search/general/api_url', 'store', 1, $apiUrl],
                ['bradsearch_search/general/api_key', 'store', 1, $token],
                ['general/locale/code', 'store', 1, 'en-US'],
            ]);
    }

    protected function setUp(): void
    {
        $this->curlMock = $this->createMock(Curl::class);
        $this->scopeConfigMock = $this->createMock(ScopeConfigInterface::class);
        $this->storeManagerMock = $this->createMock(StoreManagerInterface::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        // Mock store
        $storeMock = $this->createMock(Store::class);
        $storeMock->method('getId')->willReturn(1);
        $this->storeManagerMock->method('getStore')->willReturn($storeMock);

        $this->subject = new Client(
            $this->curlMock,
            $this->scopeConfigMock,
            $this->storeManagerMock,
            $this->loggerMock
        );
    }
}
