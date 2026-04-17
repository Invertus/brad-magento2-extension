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
            return $this->mapFacetsToAggregations($response);
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
     * @return array
     */
    private function mapFacetsToAggregations(array $response): array
    {
        $facets = $response['facets'] ?? [];

        // V1 format: facets.attributes contains grouped facets with {label, values} structure
        if ($this->isV1Response($facets)) {
            $aggregations = $this->mapV1Facets($facets);
        } else {
            $aggregations = $this->mapV2Facets($facets);
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
     * @return array
     */
    private function mapV1Facets(array $facets): array
    {
        $aggregations = [];
        $attributes = $facets['attributes'] ?? [];

        foreach ($attributes as $attributeCode => $facetData) {
            $label = $facetData['label'] ?? $attributeCode;
            $values = $facetData['values'] ?? [];
            $options = $this->mapFacetOptions($values);

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
     * @return array
     */
    private function mapV2Facets(array $facets): array
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
                    $options = $this->mapFacetOptions($values);
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
            $options = $this->mapFacetOptions($facetData);
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
     * Map facet value arrays to Magento aggregation option format
     *
     * @param array $values Array of {value, count} or {value, count, enabled}
     * @return array
     */
    private function mapFacetOptions(array $values): array
    {
        $options = [];
        foreach ($values as $facetOption) {
            if (!is_array($facetOption)) {
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
