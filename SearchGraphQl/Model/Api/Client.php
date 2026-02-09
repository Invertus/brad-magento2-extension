<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\Api;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * BradSearch API HTTP Client
 *
 * Handles HTTP communication with BradSearch API.
 * Reads configuration for API URL and token.
 */
class Client
{
    private const CONFIG_PATH_API_URL = 'bradsearch_search/general/api_url';
    private const CONFIG_PATH_FACETS_API_URL = 'bradsearch_search/general/facets_api_url';
    private const CONFIG_PATH_API_KEY = 'bradsearch_search/general/api_key';

    /**
     * @var Curl
     */
    private Curl $curl;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Curl $curl
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        Curl $curl,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->curl = $curl;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    /**
     * Search products via BradSearch API
     *
     * @param string $searchTerm
     * @param int $pageSize
     * @param int $currentPage
     * @param array $filters
     * @param array $sort
     * @return array
     * @throws \Exception
     */
    public function search(string $searchTerm, int $pageSize = 18, int $currentPage = 1, array $filters = [], array $sort = []): array
    {
        $storeId = $this->getStoreId();
        $apiUrl = $this->getApiUrl($storeId);
        $token = $this->getApiToken($storeId);

        if (empty($apiUrl) || empty($token)) {
            $this->logger->error('API URL or token not configured', ['store_id' => $storeId]);
            throw new \Exception('BradSearch API URL or token not configured');
        }

        $params = $this->buildRequestParams($searchTerm, $token, $pageSize, $currentPage, $filters, $sort);
        $url = $apiUrl . '?' . http_build_query($params);

        $this->logger->debug('Making API request', [
            'url' => $apiUrl,
            'search_term' => $searchTerm,
            'page_size' => $pageSize,
            'current_page' => $currentPage,
        ]);

        try {
            $this->setupCurl();
            $this->curl->get($url);

            $statusCode = $this->curl->getStatus();
            $body = $this->curl->getBody();

            $this->logger->debug('API response received', [
                'status_code' => $statusCode,
                'body_length' => strlen($body),
            ]);

            if ($statusCode !== 200) {
                throw new \Exception("BradSearch API returned status code: $statusCode");
            }

            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Failed to decode BradSearch API response: ' . json_last_error_msg());
            }

            return $data;
        } catch (\Throwable $e) {
            $this->logger->error('API request failed', [
                'error' => $e->getMessage(),
                'search_term' => $searchTerm,
            ]);
            throw $e;
        }
    }

    /**
     * Fetch facets/aggregations via BradSearch API
     *
     * @param string $searchTerm
     * @return array
     * @throws \Exception
     */
    public function fetchFacets(string $searchTerm): array
    {
        $storeId = $this->getStoreId();
        $apiUrl = $this->getFacetsApiUrl($storeId);
        $token = $this->getApiToken($storeId);

        if (empty($apiUrl) || empty($token)) {
            $this->logger->error('Facets API URL or token not configured', ['store_id' => $storeId]);
            throw new \Exception('BradSearch Facets API URL or token not configured');
        }

        $params = $this->buildFacetsRequestParams($searchTerm, $token);
        $url = $apiUrl . '?' . http_build_query($params);

        $this->logger->debug('Making Facets API request', [
            'url' => $apiUrl,
            'search_term' => $searchTerm,
        ]);

        try {
            $this->setupCurl();
            $this->curl->get($url);

            $statusCode = $this->curl->getStatus();
            $body = $this->curl->getBody();

            $this->logger->debug('Facets API response received', [
                'status_code' => $statusCode,
                'body_length' => strlen($body),
            ]);

            if ($statusCode !== 200) {
                throw new \Exception("BradSearch Facets API returned status code: $statusCode");
            }

            $data = json_decode($body, true);

            if (json_last_error() !== JSON_ERROR_NONE) {
                throw new \Exception('Failed to decode BradSearch Facets API response: ' . json_last_error_msg());
            }

            return $data;
        } catch (\Throwable $e) {
            $this->logger->error('Facets API request failed', [
                'error' => $e->getMessage(),
                'search_term' => $searchTerm,
            ]);
            throw $e;
        }
    }

    /**
     * Build request parameters for facets API call
     *
     * @param string $searchTerm
     * @param string $token
     * @return array
     */
    private function buildFacetsRequestParams(string $searchTerm, string $token): array
    {
        return [
            'token' => $token,
            'q' => $searchTerm,
        ];
    }

    /**
     * Build request parameters for API call
     *
     * @param string $searchTerm
     * @param string $token
     * @param int $pageSize
     * @param int $currentPage
     * @param array $filters
     * @param array $sort
     * @return array
     */
    private function buildRequestParams(
        string $searchTerm,
        string $token,
        int $pageSize,
        int $currentPage,
        array $filters = [],
        array $sort = []
    ): array {
        $offset = ($currentPage - 1) * $pageSize;

        $params = [
            'token' => $token,
            'q' => $searchTerm,
            'limit' => $pageSize,
            'offset' => $offset,
            'context.price.withTaxes' => 'true',
            'fields' => 'id',
        ];

        // Add sorting parameters
        // Input from GraphQL: ['price_lt' => 'ASC'] or ['name' => 'DESC']
        // Output for API: sortby=price&order=asc
        if (!empty($sort)) {
            $sortParams = $this->transformSortParams($sort);
            if ($sortParams !== null) {
                $params['sortby'] = $sortParams['sortby'];
                $params['order'] = $sortParams['order'];
            }
        }

        // Transform Magento filters to BradSearch format
        // Input: ['attr_code' => ['eq' => 'value'] or ['in' => ['val1', 'val2']] or ['from' => '100', 'to' => '200']]
        // Output: ['attributes[attr_code][0]' => 'value'] or ['attributes[attr_code][from]' => '100', 'attributes[attr_code][to]' => '200']
        $excludedFilters = ['show_out_of_stock', 'category_id', 'category_uid'];

        foreach ($filters as $attributeCode => $condition) {
            if (in_array($attributeCode, $excludedFilters, true)) {
                continue;
            }

            // Handle range filters (from/to)
            if (isset($condition['from']) || isset($condition['to'])) {
                if (isset($condition['from'])) {
                    $params["attributes[$attributeCode][from]"] = $condition['from'];
                }
                if (isset($condition['to'])) {
                    $params["attributes[$attributeCode][to]"] = $condition['to'];
                }
                continue;
            }

            // Handle eq/in filters
            $values = [];
            if (isset($condition['eq'])) {
                $values[] = $condition['eq'];
            } elseif (isset($condition['in'])) {
                $values = $condition['in'];
            }

            foreach ($values as $index => $value) {
                $params["attributes[$attributeCode][$index]"] = $value;
            }
        }

        return $params;
    }

    /**
     * Transform GraphQL sort parameters to BradSearch API format
     *
     * GraphQL format: ['price_lt' => 'ASC', 'name' => 'DESC']
     * API format: sortby=price&order=asc
     *
     * @param array $sort
     * @return array|null Returns ['sortby' => string, 'order' => string] or null if empty
     */
    private function transformSortParams(array $sort): ?array
    {
        if (empty($sort)) {
            return null;
        }

        // Map Magento attribute codes to BradSearch sort fields
        // Magento may use store-specific codes like 'price_lt' for Lithuanian price
        $attributeMap = [
            'price' => 'price',
            'name' => 'name',
            'position' => 'position',
            'relevance' => 'relevance',
        ];

        // Get the first sort field (primary sort)
        $attributeCode = array_key_first($sort);
        $direction = $sort[$attributeCode];

        // Normalize attribute code: remove store suffixes like '_lt', '_lv', '_ee'
        $normalizedCode = preg_replace('/_[a-z]{2}$/', '', $attributeCode);

        // Map to BradSearch field or use as-is
        $sortField = $attributeMap[$normalizedCode] ?? $normalizedCode;

        // Normalize direction to lowercase
        $sortOrder = strtolower($direction) === 'desc' ? 'desc' : 'asc';

        return [
            'sortby' => $sortField,
            'order' => $sortOrder,
        ];
    }

    /**
     * Setup curl client with headers
     *
     * @return void
     */
    private function setupCurl(): void
    {
        $locale = $this->getStoreLocale();

        $this->curl->addHeader('Accept', 'application/json');
        $this->curl->addHeader('Accept-Language', $locale);
        $this->curl->setOption(CURLOPT_TIMEOUT, 10);
        $this->curl->setOption(CURLOPT_CONNECTTIMEOUT, 5);
    }

    /**
     * Get current store ID
     *
     * @return int
     */
    private function getStoreId(): int
    {
        try {
            return (int)$this->storeManager->getStore()->getId();
        } catch (\Exception $e) {
            return 0;
        }
    }

    /**
     * Get store locale
     *
     * @return string
     */
    private function getStoreLocale(): string
    {
        try {
            $storeId = $this->getStoreId();
            $locale = $this->scopeConfig->getValue(
                'general/locale/code',
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
            return $locale ?: 'en-US';
        } catch (\Exception $e) {
            return 'en-US';
        }
    }

    /**
     * Get API URL from configuration
     *
     * @param int $storeId
     * @return string|null
     */
    private function getApiUrl(int $storeId): ?string
    {
        return $this->scopeConfig->getValue(
            self::CONFIG_PATH_API_URL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get Facets API URL from configuration
     *
     * @param int $storeId
     * @return string|null
     */
    private function getFacetsApiUrl(int $storeId): ?string
    {
        return $this->scopeConfig->getValue(
            self::CONFIG_PATH_FACETS_API_URL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get API token from configuration
     *
     * @param int $storeId
     * @return string|null
     */
    private function getApiToken(int $storeId): ?string
    {
        return $this->scopeConfig->getValue(
            self::CONFIG_PATH_API_KEY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

}
