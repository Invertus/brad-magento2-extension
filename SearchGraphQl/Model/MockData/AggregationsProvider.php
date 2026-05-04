<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\MockData;

use BradSearch\SearchGraphQl\Model\Api\Client;
use Psr\Log\LoggerInterface;

/**
 * Provides aggregation data from BradSearch API for search results.
 *
 * Fetches facets from the BradSearch API and transforms them into
 * the Magento aggregations format for the PWA frontend.
 *
 * IMPORTANT: For filters to render in PWA, the attribute_code must:
 * 1. Exist in ProductAttributeFilterInput GraphQL schema
 * 2. Have is_filterable=1 or is_filterable_in_search=1 in Magento
 * 3. Use string values for option 'value' field (like "Bosch", not "101")
 */
class AggregationsProvider
{
    /**
     * @var Client
     */
    private Client $client;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Client $client
     * @param LoggerInterface $logger
     */
    public function __construct(
        Client $client,
        LoggerInterface $logger
    ) {
        $this->client = $client;
        $this->logger = $logger;
    }

    /**
     * Get aggregations for search results from BradSearch API
     *
     * Returns array keyed by attribute_code
     *
     * @param string $searchTerm
     * @param array $filters Magento filter array
     * @return array
     * @throws \Exception
     */
    public function getAggregations(string $searchTerm, array $filters = []): array
    {
        try {
            $response = $this->client->fetchFacets($searchTerm, $filters);
            return $this->mapFacetsToAggregations($response, $filters);
        } catch (\Throwable $e) {
            $this->logger->error('BradSearch Facets API call failed', [
                'error' => $e->getMessage(),
                'search_term' => $searchTerm,
            ]);

            // Re-throw exception to allow Plugin to fallback to default aggregations
            throw $e;
        }
    }

    /**
     * Transform BradSearch API facets response to Magento aggregations format
     *
     * Detects v1 vs v2 response format and delegates accordingly.
     *
     * @param array $response
     * @param array $appliedFilters Magento filter array — used to keep within-facet
     *     alternatives visible for facets the user is actively filtering on
     * @return array
     */
    private function mapFacetsToAggregations(array $response, array $appliedFilters = []): array
    {
        $facets = $response['facets'] ?? [];

        // V1 format: facets.attributes contains grouped facets with {label, values} structure
        if ($this->isV1Response($facets)) {
            $aggregations = $this->mapV1Facets($facets, $appliedFilters);
        } else {
            $aggregations = $this->mapV2Facets($facets, $appliedFilters);
        }

        $this->logger->debug('Mapped facets to aggregations', [
            'attribute_count' => count($aggregations),
            'attributes' => array_keys($aggregations),
        ]);

        return $aggregations;
    }

    /**
     * Detect v1 response format by checking for attributes wrapper with {label, values} structure
     *
     * @param array $facets
     * @return bool
     */
    private function isV1Response(array $facets): bool
    {
        if (!isset($facets['attributes']) || !is_array($facets['attributes'])) {
            return false;
        }

        // V1 attributes have {label, values} structure per attribute
        $firstAttribute = reset($facets['attributes']);
        return is_array($firstAttribute) && (isset($firstAttribute['label']) || isset($firstAttribute['values']));
    }

    /**
     * Map v1 response format: facets.attributes.{code}.{label, values}
     *
     * @param array $facets
     * @param array $appliedFilters
     * @return array
     */
    private function mapV1Facets(array $facets, array $appliedFilters = []): array
    {
        $aggregations = [];
        $attributes = $facets['attributes'] ?? [];

        foreach ($attributes as $attributeCode => $facetData) {
            $label = $facetData['label'] ?? $attributeCode;
            $values = $facetData['values'] ?? [];
            $isFilteredFacet = array_key_exists($attributeCode, $appliedFilters);
            $options = $this->mapFacetOptions($values, $isFilteredFacet);

            if (empty($options)) {
                continue;
            }

            $aggregations[$attributeCode] = [
                'attribute_code' => $attributeCode,
                'label' => $label,
                'count' => count($options),
                'options' => $options,
            ];
        }

        return $aggregations;
    }

    /**
     * Map v2 response format: flat top-level facets + grouped features/attributes
     *
     * V2 format:
     * {
     *   "brand": [{"value": "Bosch", "count": 42, "enabled": true}],
     *   "features": {"Battery Type": [{"value": "Li-Ion", "count": 30, "enabled": true}]},
     *   "price": {"min": 10.5, "max": 999.0}
     * }
     *
     * @param array $facets
     * @param array $appliedFilters
     * @return array
     */
    private function mapV2Facets(array $facets, array $appliedFilters = []): array
    {
        $aggregations = [];

        foreach ($facets as $facetName => $facetData) {
            if (!is_array($facetData)) {
                continue;
            }

            // Range/stats facets (e.g., price): {min, max, count, sum, avg}
            if (isset($facetData['min']) || isset($facetData['max'])) {
                continue;
            }

            // Grouped facets (features/attributes): codes as keys, {label, values} structure
            // e.g. {"manufacturer": {"label": "Gamintojas", "values": [...]}}
            if ($facetName === 'features' || $facetName === 'attributes') {
                foreach ($facetData as $code => $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $label = $entry['label'] ?? $code;
                    $values = $entry['values'] ?? [];
                    $isFilteredFacet = array_key_exists($code, $appliedFilters);
                    $options = $this->mapFacetOptions($values, $isFilteredFacet);
                    if (empty($options)) {
                        continue;
                    }
                    $aggregations[$code] = [
                        'attribute_code' => $code,
                        'label' => $label,
                        'count' => count($options),
                        'options' => $options,
                    ];
                }
                continue;
            }

            // Top-level term facets (brand, categories, etc.)
            $isFilteredFacet = array_key_exists($facetName, $appliedFilters);
            $options = $this->mapFacetOptions($facetData, $isFilteredFacet);
            if (!empty($options)) {
                $aggregations[$facetName] = [
                    'attribute_code' => $facetName,
                    'label' => $facetName,
                    'count' => count($options),
                    'options' => $options,
                ];
            }
        }

        return $aggregations;
    }

    /**
     * Map facet value arrays to Magento aggregation option format.
     *
     * For facets the user is NOT currently filtering on, drop options that
     * BradSearch reports as `enabled: false` — they exist in the global
     * universe but don't match the active filter set, and should disappear
     * from the sidebar (matches category-page UX, where count:0 options
     * are filtered out server-side).
     *
     * For facets the user IS currently filtering on, keep all values so
     * within-facet alternatives stay visible (Brand=Nike + Brand=Adidas
     * multi-select). BradSearch marks the unselected siblings as
     * `enabled: false`, but we preserve them here.
     *
     * @param array $values Array of {value, count} or {value, count, enabled}
     * @param bool $isFilteredFacet True if this facet has an active user filter
     * @return array
     */
    private function mapFacetOptions(array $values, bool $isFilteredFacet = false): array
    {
        $options = [];
        foreach ($values as $facetOption) {
            if (!is_array($facetOption)) {
                continue;
            }
            if (!$isFilteredFacet
                && array_key_exists('enabled', $facetOption)
                && $facetOption['enabled'] === false) {
                continue;
            }
            $value = $facetOption['value'] ?? '';
            $count = $facetOption['count'] ?? 0;

            $options[] = [
                'label' => $value,
                'value' => $value,
                'value_extra' => null,
                'count' => (int)$count,
            ];
        }
        return $options;
    }
}
