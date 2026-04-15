<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\ProductFeatures\Model;

/**
 * Shared attribute filtering logic for ProductFeatures resolvers.
 * Determines which catalog attributes to exclude from BradSearch responses.
 */
class AttributeFilter
{
    /**
     * System attributes to exclude from custom attributes response
     */
    public const EXCLUDED_ATTRIBUTES = [
        'sku',
        'name',
        'entity_id',
        'attribute_set_id',
        'type_id',
        'row_id',
        'description',
        'short_description',
        'price',
        'special_price',
        'cost',
        'tier_price',
        'msrp',
        'msrp_display_actual_price_type',
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
        'image',
        'small_image',
        'thumbnail',
        'swatch_image',
        'image_label',
        'small_image_label',
        'thumbnail_label',
        'media_gallery',
        'gallery',
        'url_key',
        'url_path',
        'request_path',
        'meta_title',
        'meta_keyword',
        'meta_description',
        'created_at',
        'updated_at',
        'news_from_date',
        'news_to_date',
        'special_from_date',
        'special_to_date',
        'page_layout',
        'custom_layout',
        'custom_layout_update',
        'custom_design',
        'custom_design_from',
        'custom_design_to',
        'gift_message_available',
        'gift_wrapping_available',
        'gift_wrapping_price',
        'links_purchased_separately',
        'links_title',
        'links_exist',
        'samples_title',
    ];

    /**
     * Attribute prefixes to exclude (e.g., price_at, price_de, etc.)
     * These expose country-specific pricing and should use price_range instead
     */
    public const EXCLUDED_ATTRIBUTE_PREFIXES = [
        'price_'
    ];

    /**
     * @var array|null Flipped lookup for O(1) exclusion checks
     */
    private static ?array $excludedLookup = null;

    /**
     * Check if an attribute code should be excluded
     *
     * @param string $attributeCode
     * @return bool
     */
    public static function isExcluded(string $attributeCode): bool
    {
        if (self::$excludedLookup === null) {
            self::$excludedLookup = array_flip(self::EXCLUDED_ATTRIBUTES);
        }

        if (isset(self::$excludedLookup[$attributeCode])) {
            return true;
        }

        foreach (self::EXCLUDED_ATTRIBUTE_PREFIXES as $prefix) {
            if (str_starts_with($attributeCode, $prefix)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Format attribute code to human-readable label (snake_case to Title Case)
     *
     * @param string $attributeCode
     * @return string
     */
    public static function formatLabel(string $attributeCode): string
    {
        return implode(' ', array_map('ucfirst', explode('_', $attributeCode)));
    }
}
