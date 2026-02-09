<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\Api;

use BradSearch\SearchGraphQl\Model\PopularityExtractor;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\CatalogGraphQl\Model\Resolver\Products\SearchResult;
use Magento\Framework\Api\SearchCriteriaBuilder;
use Magento\Framework\Exception\NoSuchEntityException;
use Psr\Log\LoggerInterface;

/**
 * Maps BradSearch API response to Magento GraphQL format
 *
 * Uses hybrid approach: BradSearch for search/ranking, Magento for product data.
 * This ensures compatibility with all custom resolvers (price_range, labels, etc.)
 */
class ResponseMapper
{
    /**
     * @var ProductRepositoryInterface
     */
    private ProductRepositoryInterface $productRepository;

    /**
     * @var SearchCriteriaBuilder
     */
    private SearchCriteriaBuilder $searchCriteriaBuilder;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var PopularityExtractor
     */
    private PopularityExtractor $popularityExtractor;

    /**
     * @param ProductRepositoryInterface $productRepository
     * @param SearchCriteriaBuilder $searchCriteriaBuilder
     * @param LoggerInterface $logger
     * @param PopularityExtractor $popularityExtractor
     */
    public function __construct(
        ProductRepositoryInterface $productRepository,
        SearchCriteriaBuilder $searchCriteriaBuilder,
        LoggerInterface $logger,
        PopularityExtractor $popularityExtractor
    ) {
        $this->productRepository = $productRepository;
        $this->searchCriteriaBuilder = $searchCriteriaBuilder;
        $this->logger = $logger;
        $this->popularityExtractor = $popularityExtractor;
    }

    /**
     * Map API response to GraphQL products format
     *
     * @param array $apiResponse
     * @param int $pageSize
     * @param int $currentPage
     * @return array
     */
    public function map(array $apiResponse, int $pageSize, int $currentPage): array
    {
        $documents = $apiResponse['documents'] ?? [];
        $total = (int)($apiResponse['total'] ?? 0);
        $totalPages = $pageSize > 0 ? (int)ceil($total / $pageSize) : 0;

        // Extract product IDs from API response (preserve order)
        $productIds = $this->extractProductIds($documents);

        // Load real Magento products by IDs
        $magentoProducts = $this->loadProductsByIds($productIds);

        // Map to GraphQL format, preserving BradSearch order
        $items = $this->mapToGraphQlItems($documents, $magentoProducts);

        $searchResult = new SearchResult([
            'totalCount' => $total,
            'productsSearchResult' => $items,
            'searchAggregation' => null,
            'pageSize' => $pageSize,
            'currentPage' => $currentPage,
            'totalPages' => $totalPages,
        ]);

        return [
            'total_count' => $total,
            'items' => $items,
            'page_info' => [
                'total_pages' => $totalPages,
                'current_page' => $currentPage,
                'page_size' => $pageSize,
            ],
            'search_result' => $searchResult,
            'layer_type' => 'search',
        ];
    }

    /**
     * Extract product IDs from API documents
     *
     * @param array $documents
     * @return array
     */
    private function extractProductIds(array $documents): array
    {
        $ids = [];
        foreach ($documents as $doc) {
            if (!empty($doc['id'])) {
                $ids[] = $doc['id'];
            }
        }
        return $ids;
    }

    /**
     * Load Magento products by IDs
     *
     * @param array $productIds
     * @return array Indexed by product ID
     */
    private function loadProductsByIds(array $productIds): array
    {
        if (empty($productIds)) {
            return [];
        }

        $this->logger->debug('Loading products from Magento', ['ids' => $productIds]);

        try {
            $searchCriteria = $this->searchCriteriaBuilder
                ->addFilter('entity_id', $productIds, 'in')
                ->create();

            $searchResults = $this->productRepository->getList($searchCriteria);

            $products = [];
            foreach ($searchResults->getItems() as $product) {
                $products[$product->getId()] = $product;
            }

            $this->logger->debug('Loaded products', ['count' => count($products)]);

            return $products;
        } catch (\Exception $e) {
            $this->logger->error('Failed to load products', ['error' => $e->getMessage()]);
            return [];
        }
    }

    /**
     * Map API documents to GraphQL items using loaded Magento products
     *
     * @param array $documents
     * @param array $magentoProducts
     * @return array
     */
    private function mapToGraphQlItems(array $documents, array $magentoProducts): array
    {
        $items = [];

        foreach ($documents as $doc) {
            $productId = $doc['id'] ?? null;

            if ($productId && isset($magentoProducts[$productId])) {
                $product = $magentoProducts[$productId];

                $items[] = [
                    'entity_id' => $product->getId(),
                    'type_id' => $product->getTypeId(),
                    'sku' => $product->getSku(),
                    'name' => $product->getName(),
                    'url_key' => $product->getUrlKey(),
                    'model' => $product,
                    'uid' => base64_encode((string)$product->getId()),
                    '__typename' => $this->getTypeName($product->getTypeId()),
                    'sort_popularity' => $this->popularityExtractor->getSortPopularity($product->getData('mm_popularity')),
                    'sort_popularity_sales' => $this->popularityExtractor->getSortPopularitySales($product->getData('mm_popularity')),
                ];
            } else {
                $this->logger->warning('Product not found in Magento', ['id' => $productId]);
            }
        }

        return $items;
    }

    /**
     * Get GraphQL typename for product type
     *
     * @param string $typeId
     * @return string
     */
    private function getTypeName(string $typeId): string
    {
        $typeMap = [
            'simple' => 'SimpleProduct',
            'configurable' => 'ConfigurableProduct',
            'bundle' => 'BundleProduct',
            'grouped' => 'GroupedProduct',
            'virtual' => 'VirtualProduct',
            'downloadable' => 'DownloadableProduct',
        ];

        return $typeMap[$typeId] ?? 'SimpleProduct';
    }

    /**
     * Create empty result set
     *
     * @param int $pageSize
     * @param int $currentPage
     * @return array
     */
    public function getEmptyResults(int $pageSize, int $currentPage): array
    {
        return $this->map(['documents' => [], 'total' => 0], $pageSize, $currentPage);
    }
}
