<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Plugin\CatalogGraphQl\Model\Resolver;

use BradSearch\SearchGraphQl\Model\MockData\ProductsProvider;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Plugin to intercept products GraphQL query and return BradSearch results
 *
 * When BradSearch is enabled and a ProductSearch operation is executed,
 * this plugin intercepts the products query and returns results from BradSearch API.
 *
 * Only intercepts the ProductSearch operation to avoid interfering with
 * category pages, filtered listings, or other product queries.
 */
class Products
{
    private const CONFIG_PATH_ENABLED = 'bradsearch_search/general/enabled';

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
     * @var ProductsProvider
     */
    private ProductsProvider $productsProvider;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param ProductsProvider $productsProvider
     * @param LoggerInterface $logger
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        ProductsProvider $productsProvider,
        LoggerInterface $logger
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->productsProvider = $productsProvider;
        $this->logger = $logger;
    }

    /**
     * Intercept products query and return BradSearch results when applicable
     *
     * @param object $subject
     * @param callable $proceed
     * @param Field $field
     * @param mixed $context
     * @param ResolveInfo $info
     * @param array|null $value
     * @param array|null $args
     * @return array
     */
    public function aroundResolve(
        $subject,
        callable $proceed,
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ): array {
        $operationName = $this->getOperationName($info);
        $hasSearch = isset($args['search']) && !empty($args['search']);

        $this->logger->debug('BradSearch plugin invoked', [
            'operation' => $operationName,
            'has_search' => $hasSearch,
        ]);

        // Check if this is a search operation
        if (!$this->isSearchOperation($args, $info)) {
            $this->logger->debug('Not intercepting: not a search operation');
            return $proceed($field, $context, $info, $value, $args);
        }

        // Check if BradSearch is enabled
        $isEnabled = $this->isBradSearchEnabled();
        $this->logger->debug('BradSearch configuration check', ['enabled' => $isEnabled]);

        if (!$isEnabled) {
            $this->logger->debug('Not intercepting: BradSearch disabled');
            return $proceed($field, $context, $info, $value, $args);
        }

        // Get search parameters
        $searchTerm = $args['search'] ?? '';
        $pageSize = (int)($args['pageSize'] ?? 18);
        $currentPage = (int)($args['currentPage'] ?? 1);
        $filters = $args['filter'] ?? [];
        $sort = $args['sort'] ?? [];

        $this->logger->info('Intercepting search with BradSearch', [
            'search_term' => $searchTerm,
            'page_size' => $pageSize,
            'current_page' => $currentPage,
            'filters' => $filters,
            'sort' => $sort,
        ]);

        // Try BradSearch, fallback to default search on error
        try {
            return $this->productsProvider->getSearchResults($searchTerm, $pageSize, $currentPage, $filters, $sort);
        } catch (\Throwable $e) {
            $this->logger->error('BradSearch API failed, falling back to default search', [
                'error' => $e->getMessage(),
                'search_term' => $searchTerm,
                'trace' => $e->getTraceAsString(),
            ]);

            // Fallback to default Magento/ElasticSuite search
            return $proceed($field, $context, $info, $value, $args);
        }
    }

    /**
     * Check if this is a ProductSearch operation
     *
     * @param array|null $args
     * @param ResolveInfo $info
     * @return bool
     */
    private function isSearchOperation(?array $args, ResolveInfo $info): bool
    {
        if (!isset($args['search']) || empty($args['search'])) {
            return false;
        }

        return $this->isProductSearchOperation($info);
    }

    /**
     * Check if the current GraphQL operation is "ProductSearch"
     *
     * @param ResolveInfo $info
     * @return bool
     */
    private function isProductSearchOperation(ResolveInfo $info): bool
    {
        if (isset($info->operation) &&
            $info->operation !== null &&
            isset($info->operation->name) &&
            $info->operation->name !== null &&
            isset($info->operation->name->value)) {

            $operationName = $info->operation->name->value;

            if (strcasecmp($operationName, self::OPERATION_NAME_PRODUCT_SEARCH) === 0) {
                return true;
            }
        }

        if (isset($_GET['operationName'])) {
            $operationName = $_GET['operationName'];

            if (strcasecmp($operationName, self::OPERATION_NAME_PRODUCT_SEARCH) === 0) {
                return true;
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
     * Get the GraphQL operation name from ResolveInfo or $_GET fallback
     *
     * @param ResolveInfo $info
     * @return string
     */
    private function getOperationName(ResolveInfo $info): string
    {
        if (isset($info->operation->name->value)) {
            return $info->operation->name->value;
        }

        if (isset($_GET['operationName'])) {
            return $_GET['operationName'];
        }

        return 'unknown';
    }
}
