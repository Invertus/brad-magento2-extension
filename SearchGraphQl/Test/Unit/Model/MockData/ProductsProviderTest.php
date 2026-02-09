<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Model\MockData;

use BradSearch\SearchGraphQl\Model\Api\Client;
use BradSearch\SearchGraphQl\Model\Api\ResponseMapper;
use BradSearch\SearchGraphQl\Model\MockData\ProductsProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProductsProviderTest extends TestCase
{
    private ProductsProvider $subject;
    private Client&MockObject $clientMock;
    private ResponseMapper&MockObject $responseMapperMock;
    private LoggerInterface&MockObject $loggerMock;

    /**
     * Test successful search results
     */
    public function testGetSearchResultsSuccess(): void
    {
        $searchTerm = 'laptop';
        $pageSize = 20;
        $currentPage = 2;
        $filters = ['color' => ['eq' => 'red']];
        $sort = ['price' => 'ASC'];

        $apiResponse = [
            'documents' => [['id' => '123'], ['id' => '456']],
            'total' => 100,
        ];

        $mappedResponse = [
            'items' => [
                ['entity_id' => 123, 'sku' => 'SKU-123'],
                ['entity_id' => 456, 'sku' => 'SKU-456'],
            ],
            'total_count' => 100,
            'page_info' => [
                'total_pages' => 5,
                'current_page' => 2,
                'page_size' => 20,
            ],
        ];

        // Expect API client to be called
        $this->clientMock
            ->expects($this->once())
            ->method('search')
            ->with($searchTerm, $pageSize, $currentPage, $filters, $sort)
            ->willReturn($apiResponse);

        // Expect response mapper to be called
        $this->responseMapperMock
            ->expects($this->once())
            ->method('map')
            ->with($apiResponse, $pageSize, $currentPage)
            ->willReturn($mappedResponse);

        $result = $this->subject->getSearchResults($searchTerm, $pageSize, $currentPage, $filters, $sort);

        $this->assertSame($mappedResponse, $result);
    }

    /**
     * Test with default parameters
     */
    public function testGetSearchResultsWithDefaults(): void
    {
        $searchTerm = 'test';

        $apiResponse = ['documents' => [], 'total' => 0];
        $mappedResponse = ['items' => [], 'total_count' => 0];

        $this->clientMock
            ->expects($this->once())
            ->method('search')
            ->with($searchTerm, 18, 1, [], [])
            ->willReturn($apiResponse);

        $this->responseMapperMock
            ->expects($this->once())
            ->method('map')
            ->willReturn($mappedResponse);

        $result = $this->subject->getSearchResults($searchTerm);

        $this->assertSame($mappedResponse, $result);
    }

    /**
     * Test API failure returns empty results
     */
    public function testGetSearchResultsApiFailure(): void
    {
        $searchTerm = 'laptop';
        $pageSize = 18;
        $currentPage = 1;

        $emptyResponse = [
            'items' => [],
            'total_count' => 0,
            'page_info' => [
                'total_pages' => 0,
                'current_page' => 1,
                'page_size' => 18,
            ],
        ];

        // API client throws exception
        $this->clientMock
            ->expects($this->once())
            ->method('search')
            ->willThrowException(new \Exception('API connection timeout'));

        // Expect empty results to be returned
        $this->responseMapperMock
            ->expects($this->once())
            ->method('getEmptyResults')
            ->with($pageSize, $currentPage)
            ->willReturn($emptyResponse);

        // Response mapper's map() should NOT be called
        $this->responseMapperMock
            ->expects($this->never())
            ->method('map');

        $result = $this->subject->getSearchResults($searchTerm, $pageSize, $currentPage);

        $this->assertSame($emptyResponse, $result);
        $this->assertSame(0, $result['total_count']);
    }

    /**
     * Test that exception is caught and logged
     */
    public function testGetSearchResultsLogsException(): void
    {
        $searchTerm = 'test';
        $exception = new \Exception('API Error');

        $this->clientMock
            ->expects($this->once())
            ->method('search')
            ->willThrowException($exception);

        $this->responseMapperMock
            ->expects($this->once())
            ->method('getEmptyResults')
            ->willReturn(['items' => [], 'total_count' => 0]);

        // Expect error to be logged
        $this->loggerMock
            ->expects($this->once())
            ->method('error')
            ->with(
                'API call failed, returning empty results',
                ['error' => 'API Error']
            );

        $this->subject->getSearchResults($searchTerm);
    }

    /**
     * Test with complex filters
     */
    public function testGetSearchResultsWithComplexFilters(): void
    {
        $filters = [
            'price' => ['from' => '100', 'to' => '500'],
            'category_id' => ['in' => [10, 20, 30]],
            'color' => ['eq' => 'blue'],
        ];
        $sort = ['position' => 'DESC', 'price' => 'ASC'];

        $apiResponse = ['documents' => [], 'total' => 0];
        $mappedResponse = ['items' => [], 'total_count' => 0];

        $this->clientMock
            ->expects($this->once())
            ->method('search')
            ->with('test', 18, 1, $filters, $sort)
            ->willReturn($apiResponse);

        $this->responseMapperMock
            ->expects($this->once())
            ->method('map')
            ->willReturn($mappedResponse);

        $result = $this->subject->getSearchResults('test', 18, 1, $filters, $sort);

        $this->assertSame($mappedResponse, $result);
    }

    /**
     * Test empty search term
     */
    public function testGetSearchResultsWithEmptySearchTerm(): void
    {
        $apiResponse = ['documents' => [], 'total' => 0];
        $mappedResponse = ['items' => [], 'total_count' => 0];

        $this->clientMock
            ->expects($this->once())
            ->method('search')
            ->with('')
            ->willReturn($apiResponse);

        $this->responseMapperMock
            ->expects($this->once())
            ->method('map')
            ->willReturn($mappedResponse);

        $result = $this->subject->getSearchResults('');

        $this->assertSame($mappedResponse, $result);
    }

    /**
     * Test response with large number of results
     */
    public function testGetSearchResultsWithLargeDataset(): void
    {
        $apiResponse = [
            'documents' => array_fill(0, 100, ['id' => '123']),
            'total' => 10000,
        ];

        $mappedResponse = [
            'items' => array_fill(0, 100, ['entity_id' => 123]),
            'total_count' => 10000,
        ];

        $this->clientMock
            ->expects($this->once())
            ->method('search')
            ->with('popular', 100, 1, [], [])
            ->willReturn($apiResponse);

        $this->responseMapperMock
            ->expects($this->once())
            ->method('map')
            ->with($apiResponse, 100, 1)
            ->willReturn($mappedResponse);

        $result = $this->subject->getSearchResults('popular', 100, 1);

        $this->assertCount(100, $result['items']);
        $this->assertSame(10000, $result['total_count']);
    }

    protected function setUp(): void
    {
        $this->clientMock = $this->createMock(Client::class);
        $this->responseMapperMock = $this->createMock(ResponseMapper::class);
        $this->loggerMock = $this->createMock(LoggerInterface::class);

        $this->subject = new ProductsProvider(
            $this->clientMock,
            $this->responseMapperMock,
            $this->loggerMock
        );
    }
}
