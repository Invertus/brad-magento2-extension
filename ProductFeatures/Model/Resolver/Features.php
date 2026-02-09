<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\ProductFeatures\Model\Resolver;

use BradSearch\ProductFeatures\Model\ProductDataLoader;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ValueFactory;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Eav\Model\ResourceModel\Entity\Attribute\Group\CollectionFactory;

/**
 * Resolver for product attributes field
 * Dynamically fetches all custom product attributes with values
 * Enhanced with formatting and positioning features
 * Filters by searchable/filterable flags and extracts unit information
 *
 * Optimized with batch loading to avoid N+1 product repository calls
 */
class Features implements ResolverInterface
{
    /**
     * @var ProductDataLoader
     */
    private ProductDataLoader $productDataLoader;

    /**
     * @var ValueFactory
     */
    private ValueFactory $valueFactory;

    /**
     * @var CollectionFactory
     */
    private CollectionFactory $groupCollection;

    /**
     * @var PriceCurrencyInterface
     */
    private PriceCurrencyInterface $priceCurrency;

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resourceConnection;

    /**
     * @var array|null Cache for catalog attribute metadata
     */
    private ?array $catalogAttributeCache = null;

    /**
     * @param ProductDataLoader $productDataLoader
     * @param ValueFactory $valueFactory
     * @param CollectionFactory $groupCollection
     * @param PriceCurrencyInterface $priceCurrency
     * @param ResourceConnection $resourceConnection
     */
    public function __construct(
        ProductDataLoader $productDataLoader,
        ValueFactory $valueFactory,
        CollectionFactory $groupCollection,
        PriceCurrencyInterface $priceCurrency,
        ResourceConnection $resourceConnection
    ) {
        $this->productDataLoader = $productDataLoader;
        $this->valueFactory = $valueFactory;
        $this->groupCollection = $groupCollection;
        $this->priceCurrency = $priceCurrency;
        $this->resourceConnection = $resourceConnection;
    }

    /**
     * System attributes to exclude from custom attributes response
     */
    private const EXCLUDED_ATTRIBUTES = [
        // Core identifiers
        'sku',
        'name',
        'entity_id',
        'attribute_set_id',
        'type_id',
        'row_id',
        // Content
        'description',
        'short_description',
        // Pricing (handled separately via price_range)
        'price',
        'special_price',
        'cost',
        'tier_price',
        'msrp',
        'msrp_display_actual_price_type',
        // Product settings
        'weight',
        'status',
        'visibility',
        'tax_class_id',
        'category_ids',
        'options_container',
        'required_options',
        'has_options',
        'quantity_and_stock_status',
        'country_of_manufacture',
        // Media
        'image',
        'small_image',
        'thumbnail',
        'swatch_image',
        'image_label',
        'small_image_label',
        'thumbnail_label',
        'media_gallery',
        'gallery',
        // URLs and SEO
        'url_key',
        'url_path',
        'request_path',
        'meta_title',
        'meta_keyword',
        'meta_description',
        // Dates
        'created_at',
        'updated_at',
        'news_from_date',
        'news_to_date',
        'special_from_date',
        'special_to_date',
        // Layout/design
        'page_layout',
        'custom_layout',
        'custom_layout_update',
        'custom_design',
        'custom_design_from',
        'custom_design_to',
        // Gift options
        'gift_message_available',
        'gift_wrapping_available',
        'gift_wrapping_price',
        // Downloadable products
        'links_purchased_separately',
        'links_title',
        'links_exist',
        'samples_title'
    ];

    /**
     * Attribute prefixes to exclude (e.g., price_at, price_de, etc.)
     * These expose country-specific pricing and should use price_range instead
     */
    private const EXCLUDED_ATTRIBUTE_PREFIXES = [
        'price_'
    ];

    /**
     * Unit patterns for extraction - ordered by specificity
     * Each pattern captures: numeric value and unit
     */
    private const UNIT_PATTERNS = [
        // Electrical (must come before generic patterns)
        '/(\d+(?:[,\.]\d+)?)\s*(kW|kHz)\b/i',
        '/(\d+(?:[,\.]\d+)?)\s*(W|V|A)\b/',
        // Torque
        '/(\d+(?:[,\.]\d+)?)\s*(Nm)\b/i',
        // Pressure
        '/(\d+(?:[,\.]\d+)?)\s*(bar|PSI)\b/i',
        // Speed/Frequency
        '/(\d+(?:[,\.]\d+)?)\s*(rpm|Hz)\b/i',
        // Sound
        '/(\d+(?:[,\.]\d+)?)\s*(dB)\b/i',
        // Length/Dimension - specific units first
        '/(\d+(?:[,\.]\d+)?)\s*(mm|cm)\b/i',
        '/(\d+(?:[,\.]\d+)?)\s*m\b(?!\w)/i',
        // Inches (various notations)
        '/(\d+(?:[,\.]\d+)?)\s*["″\'\']/i',
        // Weight
        '/(\d+(?:[,\.]\d+)?)\s*(kg|g)\b/i',
        // Volume
        '/(\d+(?:[,\.]\d+)?)\s*(ml|l)\b/i',
        // Angle
        '/(\d+(?:[,\.]\d+)?)\s*°/i',
    ];

    /**
     * @inheritdoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ) {
        if (!isset($value['model'])) {
            return null;
        }

        /** @var Product $product */
        $product = $value['model'];
        $productId = (int)$product->getId();
        $storeId = (int)$context->getExtensionAttributes()->getStore()->getId();
        $groupName = $args['group'] ?? '';

        // Add to batch queue for deferred loading
        $this->productDataLoader->addToQueue($productId, $storeId);

        // Pre-load catalog attribute metadata (once per request)
        $catalogAttributeData = $this->getCatalogAttributeData();

        // Return deferred value
        return $this->valueFactory->create(
            function () use ($productId, $storeId, $groupName, $catalogAttributeData) {
                $product = $this->productDataLoader->getProduct($productId, $storeId);

                if (!$product) {
                    return [];
                }

                if ($groupName) {
                    return $this->getAttributesByGroup($product, $groupName);
                }

                return $this->getAllAttributes($product, $catalogAttributeData);
            }
        );
    }

    /**
     * Get all custom attributes for a product
     * Filters by: searchable OR filterable flags, plus whitelist attributes
     *
     * @param Product $product
     * @param array $catalogAttributeData
     * @return array
     */
    private function getAllAttributes(Product $product, array $catalogAttributeData): array
    {
        $customAttributes = [];

        // Get all product attributes
        $attributes = $product->getAttributes();

        foreach ($attributes as $attribute) {
            $attributeCode = $attribute->getAttributeCode();

            // Skip excluded system attributes
            if (in_array($attributeCode, self::EXCLUDED_ATTRIBUTES)) {
                continue;
            }

            // Skip attributes matching excluded prefixes (e.g., price_*)
            if ($this->hasExcludedPrefix($attributeCode)) {
                continue;
            }

            // Get catalog attribute flags
            $attrMeta = $catalogAttributeData[$attributeCode] ?? [
                'is_searchable' => false,
                'is_filterable' => false,
                'is_filterable_in_search' => false
            ];

            // Skip if no value
            if (!$product->getData($attributeCode)) {
                continue;
            }

            // Get frontend value
            $frontend = $attribute->getFrontend();
            $value = $frontend->getValue($product);

            // Skip empty values and false boolean values
            if ((string)$value === '' ||
                ($attribute->getFrontendInput() === 'boolean' && $product->getData($attributeCode) == 0)) {
                continue;
            }

            // Format price attributes if needed
            if ($attribute->getFrontendInput() === 'price' &&
                is_string($value) &&
                strpos($attribute->getAttributeCode(), 'price') !== false) {
                $value = $this->priceCurrency->convertAndFormat($value);
            }

            // Only include string values with content
            if (is_string($value) && strlen($value)) {
                $label = $attribute->getStoreLabel() ?? '';
                // Remove (Slider) suffix from labels if present
                $label = str_replace('(Slider)', '', $label);

                // Extract unit and numeric value
                $unitData = $this->extractUnitAndValue($value);

                // Determine if attribute should be filterable
                // Override to false for slider-type attributes (attributes ending with '_slider')
                $isFilterable = (bool)($attrMeta['is_filterable_in_search'] ?? false);
                if ($isFilterable && str_ends_with($attributeCode, '_slider')) {
                    // Slider-type attributes are not supported by PWA frontend
                    $isFilterable = false;
                }

                $customAttributes[] = [
                    'code' => $attributeCode,
                    'label' => $label ?: $this->formatLabel($attributeCode),
                    'value' => $value,
                    'formatted' => $this->getFormattedValue($attribute, $value),
                    'position' => $attrMeta['position'] ?? null,
                    'attribute_id' => $attrMeta['attribute_id'] ?? null,
                    'is_searchable' => (bool)$attrMeta['is_searchable'],
                    'is_filterable' => $isFilterable,
                    'is_visible' => (bool)$attribute->getIsVisibleOnFront(),
                    'unit' => $unitData['unit'],
                    'numeric_value' => $unitData['numeric_value'],
                    'has_unit' => $unitData['has_unit']
                ];
            }
        }

        return $customAttributes;
    }

    /**
     * Get attributes filtered by attribute group
     *
     * @param Product $product
     * @param string $groupName
     * @return array
     */
    private function getAttributesByGroup(Product $product, string $groupName): array
    {
        $customAttributes = [];

        $groupModel = $this->getAttributeGroupId($product->getAttributeSetId(), $groupName);
        if (!$groupModel || !$groupModel->getId()) {
            return [];
        }

        $attributes = $product->getAttributes();
        $attributesGroup = $this->getGroupAttributes($product, $groupModel->getId(), $attributes);

        foreach ($attributesGroup as $attribute) {
            $attributeCode = $attribute->getAttributeCode();
            $optionsLabels = $product->getAttributeText($attributeCode);

            if (is_array($optionsLabels)) {
                $optionsLabels = implode(', ', $optionsLabels);
            }

            $value = $optionsLabels ?: $product->getData($attributeCode);

            if ($value && $attribute->getIsVisibleOnFront()) {
                $catalogAttrData = $this->catalogAttributeCache[$attributeCode] ?? [];
                $customAttributes[] = [
                    'code' => $attributeCode,
                    'label' => $attribute->getStoreLabel(),
                    'value' => (string)$value,
                    'formatted' => $this->getFormattedValue($attribute, $value),
                    'position' => $catalogAttrData['position'] ?? null,
                    'attribute_id' => $catalogAttrData['attribute_id'] ?? null
                ];
            }
        }

        return $customAttributes;
    }

    /**
     * Get formatted value using mm_format attribute property
     *
     * @param \Magento\Eav\Model\Attribute $attribute
     * @param mixed $value
     * @return string
     */
    private function getFormattedValue($attribute, $value): string
    {
        if ($format = $attribute->getData('mm_format')) {
            return sprintf($format, $value);
        }

        return (string)$value;
    }

    /**
     * Get attribute group by name
     *
     * @param int $attributeSetId
     * @param string $groupName
     * @return \Magento\Eav\Model\Entity\Attribute\Group|null
     */
    private function getAttributeGroupId(int $attributeSetId, string $groupName)
    {
        $groupCollection = $this->groupCollection->create();
        $groupCollection->addFieldToFilter('attribute_set_id', $attributeSetId);
        $groupCollection->addFieldToFilter('attribute_group_name', $groupName);

        return $groupCollection->getFirstItem();
    }

    /**
     * Get attributes that belong to a specific group
     *
     * @param Product $product
     * @param int $groupId
     * @param array $productAttributes
     * @return array
     */
    private function getGroupAttributes(Product $product, int $groupId, array $productAttributes): array
    {
        $data = [];
        foreach ($productAttributes as $attribute) {
            if ($attribute->isInGroup($product->getAttributeSetId(), $groupId)) {
                $data[] = $attribute;
            }
        }

        return $data;
    }

    /**
     * Format attribute code to human-readable label
     *
     * @param string $attributeCode
     * @return string
     */
    private function formatLabel(string $attributeCode): string
    {
        // Convert snake_case to Title Case
        $words = explode('_', $attributeCode);
        $words = array_map('ucfirst', $words);
        return implode(' ', $words);
    }

    /**
     * Get catalog attribute metadata (is_searchable, is_filterable) from database
     * Results are cached for performance
     *
     * @return array
     */
    private function getCatalogAttributeData(): array
    {
        if ($this->catalogAttributeCache !== null) {
            return $this->catalogAttributeCache;
        }

        $connection = $this->resourceConnection->getConnection();
        $select = $connection->select()
            ->from(
                ['ea' => $connection->getTableName('eav_attribute')],
                ['attribute_code', 'attribute_id']
            )
            ->joinInner(
                ['cea' => $connection->getTableName('catalog_eav_attribute')],
                'ea.attribute_id = cea.attribute_id',
                ['is_searchable', 'is_filterable', 'is_filterable_in_search', 'position']
            )
            ->where('ea.entity_type_id = ?', 4); // 4 = catalog_product entity type

        $result = $connection->fetchAll($select);

        $this->catalogAttributeCache = [];
        foreach ($result as $row) {
            $this->catalogAttributeCache[$row['attribute_code']] = [
                'attribute_id' => (int)$row['attribute_id'],
                'position' => (int)$row['position'],
                'is_searchable' => (bool)$row['is_searchable'],
                'is_filterable' => (bool)$row['is_filterable'],
                'is_filterable_in_search' => (bool)$row['is_filterable_in_search']
            ];
        }

        return $this->catalogAttributeCache;
    }

    /**
     * Extract unit and numeric value from attribute value string
     *
     * @param string $value
     * @return array ['unit' => string|null, 'numeric_value' => float|null, 'has_unit' => bool]
     */
    private function extractUnitAndValue(string $value): array
    {
        $result = [
            'unit' => null,
            'numeric_value' => null,
            'has_unit' => false
        ];

        foreach (self::UNIT_PATTERNS as $pattern) {
            if (preg_match($pattern, $value, $matches)) {
                // Extract numeric value (handle both . and , as decimal separator)
                $numericStr = str_replace(',', '.', $matches[1]);
                $numericValue = (float)$numericStr;

                // Extract unit (may be in group 2 or implied by pattern)
                $unit = isset($matches[2]) ? $matches[2] : $this->getUnitFromPattern($pattern, $matches[0]);

                // Normalize units
                $unit = $this->normalizeUnit($unit);

                $result['unit'] = $unit;
                $result['numeric_value'] = $numericValue;
                $result['has_unit'] = true;

                break; // Use first matching pattern
            }
        }

        return $result;
    }

    /**
     * Get unit from pattern when not in capture group
     *
     * @param string $pattern
     * @param string $match
     * @return string
     */
    private function getUnitFromPattern(string $pattern, string $match): string
    {
        // For patterns like degrees or inches where unit is a symbol
        if (strpos($pattern, '°') !== false) {
            return '°';
        }
        if (strpos($pattern, '["″') !== false) {
            return '"';
        }
        if (strpos($pattern, '\s*m\b') !== false) {
            return 'm';
        }

        return '';
    }

    /**
     * Normalize unit to standard format
     *
     * @param string $unit
     * @return string
     */
    private function normalizeUnit(string $unit): string
    {
        $normalizations = [
            '″' => '"',
            "'" => '"',
            "'" => '"',
            'L' => 'l',
        ];

        return $normalizations[$unit] ?? $unit;
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
}
