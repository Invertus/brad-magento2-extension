<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\ProductFeatures\Model\Resolver;

use BradSearch\SearchGraphQl\Model\Api\Auth\ApiKeyValidator;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Exception\GraphQlAuthorizationException;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Resolver for bradFeatures query — catalog-level attribute definitions for field mapping generation.
 *
 * Returns all searchable/filterable catalog attributes without requiring product context.
 * Reuses the same getCatalogAttributeData() logic as Features resolver.
 * Requires valid X-BradSearch-Api-Key header.
 */
class FeatureDefinitions implements ResolverInterface
{
    /**
     * System attributes to exclude (same list as Features resolver)
     */
    private const EXCLUDED_ATTRIBUTES = [
        'sku', 'name', 'entity_id', 'attribute_set_id', 'type_id', 'row_id',
        'description', 'short_description',
        'price', 'special_price', 'cost', 'tier_price', 'msrp', 'msrp_display_actual_price_type',
        'weight', 'status', 'visibility', 'tax_class_id', 'category_ids', 'options_container',
        'required_options', 'has_options', 'quantity_and_stock_status', 'country_of_manufacture',
        'image', 'small_image', 'thumbnail', 'swatch_image', 'image_label', 'small_image_label',
        'thumbnail_label', 'media_gallery', 'gallery',
        'url_key', 'url_path', 'request_path', 'meta_title', 'meta_keyword', 'meta_description',
        'created_at', 'updated_at', 'news_from_date', 'news_to_date', 'special_from_date', 'special_to_date',
        'page_layout', 'custom_layout', 'custom_layout_update', 'custom_design',
        'custom_design_from', 'custom_design_to',
        'gift_message_available', 'gift_wrapping_available', 'gift_wrapping_price',
        'links_purchased_separately', 'links_title', 'links_exist', 'samples_title',
    ];

    private const EXCLUDED_ATTRIBUTE_PREFIXES = ['price_'];

    /**
     * @var ApiKeyValidator
     */
    private ApiKeyValidator $apiKeyValidator;

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resourceConnection;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @param ApiKeyValidator $apiKeyValidator
     * @param ResourceConnection $resourceConnection
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        ApiKeyValidator $apiKeyValidator,
        ResourceConnection $resourceConnection,
        StoreManagerInterface $storeManager
    ) {
        $this->apiKeyValidator = $apiKeyValidator;
        $this->resourceConnection = $resourceConnection;
        $this->storeManager = $storeManager;
    }

    /**
     * @inheritdoc
     */
    public function resolve(Field $field, $context, ResolveInfo $info, array $value = null, array $args = null)
    {
        $storeId = (int)$this->storeManager->getStore()->getId();

        if (!$this->apiKeyValidator->isValidRequest($storeId)) {
            throw new GraphQlAuthorizationException(__('Invalid or missing BradSearch API key.'));
        }

        return $this->getFeatureDefinitions($storeId);
    }

    /**
     * Get all searchable/filterable catalog attribute definitions
     *
     * @param int $storeId
     * @return array
     */
    private function getFeatureDefinitions(int $storeId): array
    {
        $connection = $this->resourceConnection->getConnection();

        $select = $connection->select()
            ->from(
                ['ea' => $connection->getTableName('eav_attribute')],
                ['attribute_code', 'attribute_id', 'frontend_label']
            )
            ->joinInner(
                ['cea' => $connection->getTableName('catalog_eav_attribute')],
                'ea.attribute_id = cea.attribute_id',
                ['is_searchable', 'is_filterable', 'is_filterable_in_search', 'position']
            )
            ->where('ea.entity_type_id = ?', 4) // catalog_product entity type
            ->where('(cea.is_searchable = 1 OR cea.is_filterable = 1 OR cea.is_filterable_in_search = 1)');

        // Try to get store-specific labels
        $labelSelect = $connection->select()
            ->from(
                ['eal' => $connection->getTableName('eav_attribute_label')],
                ['attribute_id', 'value']
            )
            ->where('eal.store_id = ?', $storeId);

        $storeLabels = [];
        foreach ($connection->fetchAll($labelSelect) as $row) {
            $storeLabels[(int)$row['attribute_id']] = $row['value'];
        }

        $result = $connection->fetchAll($select);
        $features = [];

        foreach ($result as $row) {
            $code = $row['attribute_code'];

            if (in_array($code, self::EXCLUDED_ATTRIBUTES, true)) {
                continue;
            }

            if ($this->hasExcludedPrefix($code)) {
                continue;
            }

            $attributeId = (int)$row['attribute_id'];
            $label = $storeLabels[$attributeId] ?? $row['frontend_label'] ?? $this->formatLabel($code);
            $isFilterable = (bool)($row['is_filterable_in_search'] ?? false);

            // Skip slider-type attributes (not supported by PWA frontend)
            if ($isFilterable && str_ends_with($code, '_slider')) {
                $isFilterable = false;
            }

            $features[] = [
                'code' => $code,
                'label' => $label ?: $this->formatLabel($code),
                'is_searchable' => (bool)$row['is_searchable'],
                'is_filterable' => $isFilterable,
                'position' => (int)$row['position'] ?: null,
            ];
        }

        return $features;
    }

    /**
     * Check if attribute code starts with any excluded prefix
     *
     * @param string $attributeCode
     * @return bool
     */
    private function hasExcludedPrefix(string $attributeCode): bool
    {
        foreach (self::EXCLUDED_ATTRIBUTE_PREFIXES as $prefix) {
            if (strpos($attributeCode, $prefix) === 0) {
                return true;
            }
        }
        return false;
    }

    /**
     * Format attribute code to human-readable label
     *
     * @param string $attributeCode
     * @return string
     */
    private function formatLabel(string $attributeCode): string
    {
        $words = explode('_', $attributeCode);
        $words = array_map('ucfirst', $words);
        return implode(' ', $words);
    }
}
