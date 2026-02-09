<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Plugin\CatalogGraphQl\Model\Resolver;

use BradSearch\SearchGraphQl\Model\MockData\AggregationsProvider;
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
     * Operation names that should use BradSearch for aggregations
     *
     * Note: ProductSearch is NOT included here because the Products plugin
     * already handles that operation. We only intercept getProductFiltersBySearch
     * to avoid duplicate API calls.
     */
    private const SEARCH_OPERATION_NAMES = [
        'getProductFiltersBySearch',
    ];

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
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param AggregationsProvider $aggregationsProvider
     * @param LoggerInterface $logger
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        AggregationsProvider $aggregationsProvider,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->aggregationsProvider = $aggregationsProvider;
        $this->logger = $logger;
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
        array $value = null,
        array $args = null
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

            $this->logger->debug('Intercepting aggregations for BradSearch', [
                'search_term' => $searchTerm,
            ]);

            // Try BradSearch, fallback to default aggregations on error
            try {
                return $this->aggregationsProvider->getAggregations($searchTerm);
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

        // Check operation name
        return $this->isProductSearchOperation($info);
    }

    /**
     * Check if the current GraphQL operation is a search operation
     *
     * @param ResolveInfo $info
     * @return bool
     */
    private function isProductSearchOperation(ResolveInfo $info): bool
    {
        $operationName = null;

        if (isset($info->operation) &&
            $info->operation !== null &&
            isset($info->operation->name) &&
            $info->operation->name !== null &&
            isset($info->operation->name->value)) {
            $operationName = $info->operation->name->value;
        } elseif (isset($_GET['operationName'])) {
            $operationName = $_GET['operationName'];
        }

        if ($operationName !== null) {
            foreach (self::SEARCH_OPERATION_NAMES as $searchOpName) {
                if (strcasecmp($operationName, $searchOpName) === 0) {
                    return true;
                }
            }
        }

        return false;
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
}
