<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\MockData;

use BradSearch\SearchGraphQl\Model\Api\Client;
use BradSearch\SearchGraphQl\Model\Api\ResponseMapper;
use Psr\Log\LoggerInterface;

/**
 * Provides search results from BradSearch API
 *
 * Calls BradSearch API and transforms response to GraphQL format.
 * Returns API data directly without Magento product refetch.
 */
class ProductsProvider
{
    /**
     * @var Client
     */
    private Client $apiClient;

    /**
     * @var ResponseMapper
     */
    private ResponseMapper $responseMapper;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Client $apiClient
     * @param ResponseMapper $responseMapper
     * @param LoggerInterface $logger
     */
    public function __construct(
        Client $apiClient,
        ResponseMapper $responseMapper,
        LoggerInterface $logger
    ) {
        $this->apiClient = $apiClient;
        $this->responseMapper = $responseMapper;
        $this->logger = $logger;
    }

    /**
     * Get search results from BradSearch API
     *
     * @param string $searchTerm
     * @param int $pageSize
     * @param int $currentPage
     * @param array $filters
     * @param array $sort
     * @return array
     * @throws \Exception
     */
    public function getSearchResults(string $searchTerm, int $pageSize = 18, int $currentPage = 1, array $filters = [], array $sort = []): array
    {
        $this->logger->debug('getSearchResults called', [
            'search_term' => $searchTerm,
            'page_size' => $pageSize,
            'current_page' => $currentPage,
            'filters' => $filters,
            'sort' => $sort,
        ]);

        try {
            $apiResponse = $this->apiClient->search($searchTerm, $pageSize, $currentPage, $filters, $sort);

            $this->logger->debug('API response received', [
                'total' => $apiResponse['total'] ?? 0,
                'documents_count' => count($apiResponse['documents'] ?? []),
            ]);

            return $this->responseMapper->map($apiResponse, $pageSize, $currentPage);
        } catch (\Throwable $e) {
            $this->logger->error('BradSearch API call failed', [
                'error' => $e->getMessage(),
                'search_term' => $searchTerm,
            ]);

            // Re-throw exception to allow Plugin to fallback to default search
            throw $e;
        }
    }
}
