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
     * @return array
     * @throws \Exception
     */
    public function getAggregations(string $searchTerm): array
    {
        try {
            $response = $this->client->fetchFacets($searchTerm);
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
     * Input format:
     * {
     *   "total": 150,
     *   "facets": {
     *     "attributes": {
     *       "battery_type": [
     *         {"value": "Li-Ion", "count": 42, "enabled": true}
     *       ]
     *     }
     *   }
     * }
     *
     * Output format:
     * [
     *   "battery_type" => [
     *     "attribute_code" => "battery_type",
     *     "label" => "battery_type",
     *     "count" => 1,
     *     "options" => [
     *       ["label" => "Li-Ion", "value" => "Li-Ion", "value_extra" => null, "count" => 42]
     *     ]
     *   ]
     * ]
     *
     * @param array $response
     * @return array
     */
    private function mapFacetsToAggregations(array $response): array
    {
        $aggregations = [];

        $attributes = $response['facets']['attributes'] ?? [];

        foreach ($attributes as $attributeCode => $facetData) {
            $options = [];
            $label = $facetData['label'] ?? $attributeCode;
            $values = $facetData['values'] ?? [];

            foreach ($values as $facetOption) {
                $value = $facetOption['value'] ?? '';
                $count = $facetOption['count'] ?? 0;

                $options[] = [
                    'label' => $value,
                    'value' => $value,
                    'value_extra' => null,
                    'count' => (int)$count,
                ];
            }

            $aggregations[$attributeCode] = [
                'attribute_code' => $attributeCode,
                'label' => $label,
                'count' => count($options),
                'options' => $options,
            ];
        }

        $this->logger->debug('Mapped facets to aggregations', [
            'attribute_count' => count($aggregations),
            'attributes' => array_keys($aggregations),
        ]);

        return $aggregations;
    }
}
