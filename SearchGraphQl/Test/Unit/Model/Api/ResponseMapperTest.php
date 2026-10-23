<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Model\Api;

use BradSearch\SearchGraphQl\Model\Api\Client;
use BradSearch\SearchGraphQl\Model\Api\ResponseMapper;
use BradSearch\SearchGraphQl\Model\PopularityExtractor;
use Magento\Catalog\Api\Data\ProductInterface;
use Magento\Catalog\Api\Data\ProductSearchResultsInterface;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Framework\Api\SearchCriteria;
use Magento\Framework\Api\SearchCriteriaBuilder;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ResponseMapperTest extends TestCase
{
    private ResponseMapper $subject;
    private ProductRepositoryInterface&MockObject $productRepositoryMock;
    private SearchCriteriaBuilder&MockObject $searchCriteriaBuilderMock;
    private LoggerInterface&MockObject $loggerMock;

    /**
     * Test mapping valid API response to GraphQL format
     */
    public function testMapWithValidResponse(): void
    {
        $apiResponse = [
            'documents' => [
                ['id' => '100'],
                ['id' => '200'],
            ],
            'total' => 50,
        ];

        $pageSize = 20;
        $currentPage = 2;

        // Mock products
        $product1 = $this->createProductMock(100, 'simple', 'TEST-SKU-1', 'Test Product 1', 'test-product-1');
        $product2 = $this->createProductMock(200, 'configurable', 'TEST-SKU-2', 'Test Product 2', 'test-product-2');

        $this->setupProductRepositoryMock([100, 200], [$product1, $product2]);

        $result = $this->subject->map($apiResponse, $pageSize, $currentPage);

        // Verify result structure
        $this->assertArrayHasKey('total_count', $result);
        $this->assertArrayHasKey('items', $result);
        $this->assertArrayHasKey('page_info', $result);
        $this->assertSame(50, $result['total_count']);
        $this->assertCount(2, $result['items']);

        // Verify first item
        $this->assertSame(100, $result['items'][0]['entity_id']);
        $this->assertSame('TEST-SKU-1', $result['items'][0]['sku']);

        // Verify page info
        $this->assertSame(3, $result['page_info']['total_pages']); // ceil(50/20)
        $this->assertSame(2, $result['page_info']['current_page']);
        $this->assertSame(20, $result['page_info']['page_size']);
    }

    /**
     * Test mapping with empty documents
     */
    public function testMapWithEmptyDocuments(): void
    {
        $apiResponse = [
            'documents' => [],
            'total' => 0,
        ];

        $result = $this->subject->map($apiResponse, 18, 1);

        $this->assertSame(0, $result['total_count']);
        $this->assertSame([], $result['items']);
        $this->assertSame(0, $result['page_info']['total_pages']);
    }

    /**
     * Test mapping with products not found in Magento
     */
    public function testMapWithProductsNotFound(): void
    {
        $apiResponse = [
            'documents' => [
                ['id' => '100'],
                ['id' => '999'], // This product doesn't exist in Magento
                ['id' => '200'],
            ],
            'total' => 3,
        ];

        $product1 = $this->createProductMock(100, 'simple', 'SKU-1', 'Product 1', 'product-1');
        $product2 = $this->createProductMock(200, 'simple', 'SKU-2', 'Product 2', 'product-2');

        // Only 2 products returned (999 not found)
        $this->setupProductRepositoryMock([100, 999, 200], [$product1, $product2]);

        $result = $this->subject->map($apiResponse, 18, 1);

        // Should only have 2 items (product 999 skipped)
        $this->assertCount(2, $result['items']);
        $this->assertSame(100, $result['items'][0]['entity_id']);
        $this->assertSame(200, $result['items'][1]['entity_id']);
    }

    /**
     * Test that BradSearch order is preserved
     */
    public function testMapPreservesBradSearchOrder(): void
    {
        $apiResponse = [
            'documents' => [
                ['id' => '300'],
                ['id' => '100'],
                ['id' => '200'],
            ],
            'total' => 3,
        ];

        $product1 = $this->createProductMock(100, 'simple', 'SKU-1', 'Product 1', 'product-1');
        $product2 = $this->createProductMock(200, 'simple', 'SKU-2', 'Product 2', 'product-2');
        $product3 = $this->createProductMock(300, 'simple', 'SKU-3', 'Product 3', 'product-3');

        $this->setupProductRepositoryMock([300, 100, 200], [$product1, $product2, $product3]);

        $result = $this->subject->map($apiResponse, 18, 1);

        // Verify order matches API response order (not Magento repository order)
        $this->assertSame(300, $result['items'][0]['entity_id']);
        $this->assertSame(100, $result['items'][1]['entity_id']);
        $this->assertSame(200, $result['items'][2]['entity_id']);
    }

    /**
     * Test getEmptyResults
     */
    public function testGetEmptyResults(): void
    {
        // Don't set up repository mock - getEmptyResults passes empty documents so repo is never called
        $this->searchCriteriaBuilderMock = $this->createMock(SearchCriteriaBuilder::class);
        $this->productRepositoryMock = $this->createMock(ProductRepositoryInterface::class);

        $result = $this->subject->getEmptyResults(24, 3);

        $this->assertSame(0, $result['total_count']);
        $this->assertSame([], $result['items']);
        $this->assertSame(0, $result['page_info']['total_pages']);
        $this->assertSame(3, $result['page_info']['current_page']);
        $this->assertSame(24, $result['page_info']['page_size']);
    }

    /**
     * Test the results page gets the engine's correlation id, AI search state and each shown product's ai_match
     */
    public function testMapAddsSearchTrackingForAiSearch(): void
    {
        $apiResponse = [
            'documents' => [
                ['id' => '100', 'ai_match' => 'exact'],
                ['id' => '999', 'ai_match' => 'exact'], // not in Magento: not shown, not tracked
                ['id' => '200', 'ai_match' => 'close'],
            ],
            'total' => 3,
            'ai_search' => ['used' => true, 'match' => 'exact'],
            Client::RESPONSE_CORRELATION_ID => 'corr-123',
        ];

        $product1 = $this->createProductMock(100, 'simple', 'SKU-1', 'Product 1', 'product-1');
        $product2 = $this->createProductMock(200, 'simple', 'SKU-2', 'Product 2', 'product-2');
        $this->setupProductRepositoryMock([100, 999, 200], [$product1, $product2]);

        $result = $this->subject->map($apiResponse, 18, 1);

        $this->assertSame([
            'correlation_id' => 'corr-123',
            'ai_search' => ['used' => true, 'match' => 'exact'],
            'ai_matches' => [
                ['product_id' => 100, 'ai_match' => 'exact'],
                ['product_id' => 200, 'ai_match' => 'close'],
            ],
        ], $result['bradsearch_search']);
    }

    /**
     * Test a normal (keyword) search only carries the correlation id
     */
    public function testMapAddsOnlyCorrelationIdWithoutAiSearch(): void
    {
        $apiResponse = [
            'documents' => [['id' => '100']],
            'total' => 1,
            Client::RESPONSE_CORRELATION_ID => 'corr-456',
        ];

        $product1 = $this->createProductMock(100, 'simple', 'SKU-1', 'Product 1', 'product-1');
        $this->setupProductRepositoryMock([100], [$product1]);

        $result = $this->subject->map($apiResponse, 18, 1);

        $this->assertSame([
            'correlation_id' => 'corr-456',
            'ai_search' => null,
            'ai_matches' => [],
        ], $result['bradsearch_search']);
    }

    /**
     * Test the AI search state "none" (AI found nothing) reaches the page, with no products
     */
    public function testMapAddsAiSearchNoneWithoutProducts(): void
    {
        $result = $this->subject->map([
            'documents' => [],
            'total' => 0,
            'ai_search' => ['used' => true, 'match' => 'none'],
            Client::RESPONSE_CORRELATION_ID => 'corr-789',
        ], 18, 1);

        $this->assertSame([
            'correlation_id' => 'corr-789',
            'ai_search' => ['used' => true, 'match' => 'none'],
            'ai_matches' => [],
        ], $result['bradsearch_search']);
    }

    /**
     * Test unknown AI values are dropped, so analytics never stores a value it does not know
     *
     * @dataProvider invalidAiSearchDataProvider
     */
    public function testMapIgnoresInvalidAiSearch(array $aiSearch): void
    {
        $apiResponse = [
            'documents' => [['id' => '100', 'ai_match' => 'exact']],
            'total' => 1,
            'ai_search' => $aiSearch,
            Client::RESPONSE_CORRELATION_ID => 'corr-1',
        ];

        $product1 = $this->createProductMock(100, 'simple', 'SKU-1', 'Product 1', 'product-1');
        $this->setupProductRepositoryMock([100], [$product1]);

        $result = $this->subject->map($apiResponse, 18, 1);

        $this->assertNull($result['bradsearch_search']['ai_search']);
        $this->assertSame([], $result['bradsearch_search']['ai_matches']);
    }

    /**
     * Data provider for ai_search values that must not be passed on
     */
    public static function invalidAiSearchDataProvider(): array
    {
        return [
            'not used' => [['used' => false, 'match' => 'exact']],
            'unknown match' => [['used' => true, 'match' => 'maybe']],
            'used as string' => [['used' => 'true', 'match' => 'exact']],
        ];
    }

    /**
     * Test a product's unknown ai_match is left out while the search keeps its AI state
     */
    public function testMapSkipsUnknownProductAiMatch(): void
    {
        $apiResponse = [
            'documents' => [
                ['id' => '100', 'ai_match' => 'none'],
                ['id' => '200', 'ai_match' => 'close'],
            ],
            'total' => 2,
            'ai_search' => ['used' => true, 'match' => 'close'],
        ];

        $product1 = $this->createProductMock(100, 'simple', 'SKU-1', 'Product 1', 'product-1');
        $product2 = $this->createProductMock(200, 'simple', 'SKU-2', 'Product 2', 'product-2');
        $this->setupProductRepositoryMock([100, 200], [$product1, $product2]);

        $result = $this->subject->map($apiResponse, 18, 1);

        $this->assertNull($result['bradsearch_search']['correlation_id']);
        $this->assertSame([['product_id' => 200, 'ai_match' => 'close']], $result['bradsearch_search']['ai_matches']);
    }

    /**
     * Test nothing is added when the engine sent neither a correlation id nor AI search state
     */
    public function testMapWithoutTrackingDataReturnsNull(): void
    {
        $result = $this->subject->getEmptyResults(18, 1);

        $this->assertNull($result['bradsearch_search']);
    }

    /**
     * Test mapping with various product types
     */
    public function testMapWithVariousProductTypes(): void
    {
        $apiResponse = [
            'documents' => [
                ['id' => '1'],
                ['id' => '2'],
                ['id' => '3'],
            ],
            'total' => 3,
        ];

        $simpleProduct = $this->createProductMock(1, 'simple', 'SKU-1', 'Simple', 'simple');
        $configurableProduct = $this->createProductMock(2, 'configurable', 'SKU-2', 'Configurable', 'configurable');
        $bundleProduct = $this->createProductMock(3, 'bundle', 'SKU-3', 'Bundle', 'bundle');

        $this->setupProductRepositoryMock([1, 2, 3], [$simpleProduct, $configurableProduct, $bundleProduct]);

        $result = $this->subject->map($apiResponse, 18, 1);

        $this->assertSame('SimpleProduct', $result['items'][0]['__typename']);
        $this->assertSame('ConfigurableProduct', $result['items'][1]['__typename']);
        $this->assertSame('BundleProduct', $result['items'][2]['__typename']);
    }

    /**
     * Test page calculation with various page sizes
     *
     * @dataProvider pageCalculationDataProvider
     */
    public function testPageCalculation(int $total, int $pageSize, int $expectedPages): void
    {
        $apiResponse = ['documents' => [], 'total' => $total];

        // Don't set up repository mock expectations when there are no documents
        // (the method won't be called if documents array is empty)
        if (empty($apiResponse['documents'])) {
            // Just create fresh mocks without expectations
            $this->searchCriteriaBuilderMock = $this->createMock(SearchCriteriaBuilder::class);
            $this->productRepositoryMock = $this->createMock(ProductRepositoryInterface::class);
        } else {
            $this->setupProductRepositoryMock([], []);
        }

        $result = $this->subject->map($apiResponse, $pageSize, 1);

        $this->assertSame($expectedPages, $result['page_info']['total_pages']);
    }

    /**
     * Data provider for page calculation tests
     */
    public static function pageCalculationDataProvider(): array
    {
        return [
            [100, 18, 6],  // 100 products, 18 per page = 6 pages
            [50, 20, 3],   // 50 products, 20 per page = 3 pages
            [17, 18, 1],   // 17 products, 18 per page = 1 page
            [0, 18, 0],    // 0 products = 0 pages
            [36, 18, 2],   // 36 products, 18 per page = 2 pages (exact)
        ];
    }

    /**
     * Helper to create a mock product
     */
    private function createProductMock(
        int $id,
        string $typeId,
        string $sku,
        string $name,
        string $urlKey
    ): MockObject {
        // Use generic mock that allows any method calls
        $product = $this->getMockBuilder(\stdClass::class)
            ->addMethods(['getId', 'getTypeId', 'getSku', 'getName', 'getUrlKey', 'getData'])
            ->getMock();
        $product->method('getId')->willReturn($id);
        $product->method('getTypeId')->willReturn($typeId);
        $product->method('getSku')->willReturn($sku);
        $product->method('getName')->willReturn($name);
        $product->method('getUrlKey')->willReturn($urlKey);
        return $product;
    }

    /**
     * Helper to setup product repository mock
     */
    private function setupProductRepositoryMock(array $expectedIds, array $products): void
    {
        $searchCriteriaMock = $this->createMock(SearchCriteria::class);
        $searchResultsMock = $this->createMock(ProductSearchResultsInterface::class);

        // Index products by ID
        $productsById = [];
        foreach ($products as $product) {
            $productsById[$product->getId()] = $product;
        }

        $searchResultsMock->method('getItems')->willReturn($productsById);

        $this->searchCriteriaBuilderMock
            ->expects($this->once())
            ->method('addFilter')
            ->with('entity_id', $expectedIds, 'in')
            ->willReturnSelf();

        $this->searchCriteriaBuilderMock
            ->expects($this->once())
            ->method('create')
            ->willReturn($searchCriteriaMock);

        $this->productRepositoryMock
            ->expects($this->once())
            ->method('getList')
            ->with($searchCriteriaMock)
            ->willReturn($searchResultsMock);
    }

    protected function setUp(): void
    {
        $this->productRepositoryMock = $this->createMock(ProductRepositoryInterface::class);
        $this->searchCriteriaBuilderMock = $this->createMock(SearchCriteriaBuilder::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->subject = new ResponseMapper(
            $this->productRepositoryMock,
            $this->searchCriteriaBuilderMock,
            $this->loggerMock,
            new PopularityExtractor()
        );
    }
}
