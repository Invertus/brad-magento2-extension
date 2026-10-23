<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Plugin\CatalogGraphQl\Model\Resolver;

use BradSearch\SearchGraphQl\Model\MockData\AggregationsProvider;
use BradSearch\SearchGraphQl\Model\SearchTermFilter;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Plugin to intercept aggregations resolver and return mock data when BradSearch is enabled
 *
 * This prevents inconsistent state where products come from BradSearch but
 * aggregations/facets come from Magento's native search.
 *
 * Currently returns mock aggregation data for development.
 */
class Aggregations
{
    private const CONFIG_PATH_ENABLED = 'bradsearch_search/general/enabled';

    /**
     * GraphQL operation names that should fetch aggregations from BradSearch.
     *
     * `aggregations` is a child resolver of `products`, so the Products plugin
     * cannot populate it — we have to intercept it here. Products forwards
     * `search_term` and `filters` via $value so we can reuse them.
     */
    private const SEARCH_OPERATION_NAMES = [
        self::OPERATION_NAME_PRODUCT_SEARCH,
        'getProductFiltersBySearch',
    ];

    /**
     * The PWA search page sends `ProductSearch` and `getProductFiltersBySearch`
     * for the same term and builds its filter sidebar from the latter only, so
     * facets fetched for `ProductSearch` are never read. The brand page sends
     * `ProductSearch` with an empty term and does read them, which is why the
     * skip applies only when there is a search term.
     */
    private const OPERATION_NAME_PRODUCT_SEARCH = 'ProductSearch';

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var AggregationsProvider
     */
    private AggregationsProvider $aggregationsProvider;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var SearchTermFilter
     */
    private SearchTermFilter $searchTermFilter;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param AggregationsProvider $aggregationsProvider
     * @param LoggerInterface $logger
     * @param SearchTermFilter $searchTermFilter
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        AggregationsProvider $aggregationsProvider,
        LoggerInterface $logger,
        SearchTermFilter $searchTermFilter
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->aggregationsProvider = $aggregationsProvider;
        $this->logger = $logger;
        $this->searchTermFilter = $searchTermFilter;
    }

    /**
     * Intercept aggregations and return empty array when BradSearch is enabled
     *
     * @param object $subject
     * @param callable $proceed
     * @param Field $field
     * @param mixed $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return array|null
     */
    public function aroundResolve(
        $subject,
        callable $proceed,
        Field $field,
        $context,
        ResolveInfo $info,
        ?array $value = null,
        ?array $args = null
    ) {
        $operationName = $info->operation->name->value ?? $_GET['operationName'] ?? 'NONE';

        $this->logger->debug('Aggregations Plugin Called', [
            'operation' => $operationName,
            'path' => $info->path,
            'parent_type' => $info->parentType->name,
            'layer_type' => $value['layer_type'] ?? 'NOT SET',
            'variable_values' => $info->variableValues,
            'value_keys' => array_keys($value ?? []),
        ]);

        $isEnabled = $this->isBradSearchEnabled();
        $isSearch = $this->isSearchOperation($value, $info);

        $this->logger->debug('Aggregations Plugin Decision', [
            'bradsearch_enabled' => $isEnabled,
            'is_search_operation' => $isSearch,
        ]);

        // Only intercept for search operations when BradSearch is enabled
        if ($isEnabled && $isSearch) {
            $searchTerm = $this->extractSearchTerm($value, $args, $info);
            $filters = $this->extractFilters($value, $args, $info);

            if ($searchTerm !== '' && $this->isOperation($info, self::OPERATION_NAME_PRODUCT_SEARCH)) {
                $this->logger->debug('Skipping facets request: ProductSearch aggregations are not read by the storefront', [
                    'search_term' => $searchTerm,
                ]);

                return [];
            }

            if ($this->searchTermFilter->isJunk($searchTerm)) {
                $this->logger->debug('Skipping BradSearch: search term looks like a URL', [
                    'search_term' => $searchTerm,
                ]);

                return $proceed($field, $context, $info, $value, $args);
            }

            $this->logger->debug('Intercepting aggregations for BradSearch', [
                'search_term' => $searchTerm,
                'filter_count' => count($filters),
            ]);

            // Try BradSearch, fallback to default aggregations on error
            try {
                return $this->aggregationsProvider->getAggregations($searchTerm, $filters);
            } catch (\Throwable $e) {
                $this->logger->error('BradSearch API failed, falling back to default aggregations', [
                    'error' => $e->getMessage(),
                    'search_term' => $searchTerm,
                ]);

                // Fallback to default Magento/ElasticSuite aggregations
                return $proceed($field, $context, $info, $value, $args);
            }
        }

        $this->logger->debug('Not intercepting aggregations - using default resolver');
        return $proceed($field, $context, $info, $value, $args);
    }

    /**
     * Check if this is a search operation
     *
     * @param array|null $value
     * @param ResolveInfo $info
     * @return bool
     */
    private function isSearchOperation(?array $value, ResolveInfo $info): bool
    {
        // Check layer_type from parent resolver
        if (isset($value['layer_type']) && $value['layer_type'] === 'search') {
            return true;
        }

        foreach (self::SEARCH_OPERATION_NAMES as $searchOpName) {
            if ($this->isOperation($info, $searchOpName)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if the current GraphQL operation has the given name
     *
     * @param ResolveInfo $info
     * @param string $operationName
     * @return bool
     */
    private function isOperation(ResolveInfo $info, string $operationName): bool
    {
        $currentOperationName = $this->getOperationName($info);

        return $currentOperationName !== null && strcasecmp($currentOperationName, $operationName) === 0;
    }

    /**
     * Get the current GraphQL operation name from the query or the request
     *
     * @param ResolveInfo $info
     * @return string|null
     */
    private function getOperationName(ResolveInfo $info): ?string
    {
        if (isset($info->operation) &&
            $info->operation !== null &&
            isset($info->operation->name) &&
            $info->operation->name !== null &&
            isset($info->operation->name->value)) {
            return (string)$info->operation->name->value;
        }

        if (isset($_GET['operationName'])) {
            return (string)$_GET['operationName'];
        }

        return null;
    }

    /**
     * Check if BradSearch is enabled in configuration
     *
     * @return bool
     */
    private function isBradSearchEnabled(): bool
    {
        try {
            $storeId = $this->storeManager->getStore()->getId();
            return (bool)$this->scopeConfig->getValue(
                self::CONFIG_PATH_ENABLED,
                ScopeInterface::SCOPE_STORE,
                $storeId
            );
        } catch (\Exception $e) {
            return false;
        }
    }

    /**
     * Extract search term from context
     *
     * @param array|null $value
     * @param array|null $args
     * @param ResolveInfo $info
     * @return string
     */
    private function extractSearchTerm(?array $value, ?array $args, ResolveInfo $info): string
    {
        // Try to get search term from value context (passed from Products resolver)
        if (isset($value['search_term'])) {
            return (string)$value['search_term'];
        }

        // Try to get from args
        if (isset($args['search'])) {
            return (string)$args['search'];
        }

        // Try to get from GraphQL variables
        // PWA uses 'search' for getProductFiltersBySearch and 'inputText' for ProductSearch
        if (isset($info->variableValues['search'])) {
            return (string)$info->variableValues['search'];
        }

        if (isset($info->variableValues['inputText'])) {
            return (string)$info->variableValues['inputText'];
        }

        return '';
    }

    /**
     * Extract filters from context, excluding non-search filters
     *
     * @param array|null $value
     * @param array|null $args
     * @param ResolveInfo $info
     * @return array
     */
    private function extractFilters(?array $value, ?array $args, ResolveInfo $info): array
    {
        // Try value context first (forwarded from Products plugin)
        $filters = $value['filters'] ?? null;

        // Try direct GraphQL argument
        if (empty($filters) && isset($args['filter'])) {
            $filters = $args['filter'];
        }

        // Try GraphQL variables
        if (empty($filters) && isset($info->variableValues['filter'])) {
            $filters = $info->variableValues['filter'];
        }

        if (!is_array($filters) || empty($filters)) {
            return [];
        }

        return $filters;
    }
}
