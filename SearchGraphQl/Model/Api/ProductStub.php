<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\Api;

use Magento\Framework\DataObject;

/**
 * Lightweight product stub for BradSearch API results
 *
 * Holds API data and provides methods that GraphQL resolvers may call.
 * Returns defaults for methods that require Magento product model.
 */
class ProductStub extends DataObject
{
    /**
     * Get product ID
     *
     * @return int|null
     */
    public function getId(): ?int
    {
        $id = $this->getData('entity_id');
        return $id !== null ? (int)$id : null;
    }

    /**
     * Get product SKU
     *
     * @return string|null
     */
    public function getSku(): ?string
    {
        return $this->getData('sku');
    }

    /**
     * Get product name
     *
     * @return string|null
     */
    public function getName(): ?string
    {
        return $this->getData('name');
    }

    /**
     * Get product type ID
     *
     * @return string
     */
    public function getTypeId(): string
    {
        return $this->getData('type_id') ?? 'simple';
    }

    /**
     * Get URL key
     *
     * @return string|null
     */
    public function getUrlKey(): ?string
    {
        return $this->getData('url_key');
    }

    /**
     * Get price
     *
     * @return float|null
     */
    public function getPrice(): ?float
    {
        $price = $this->getData('price');
        return $price !== null ? (float)$price : null;
    }

    /**
     * Get special price (price tax excluded)
     *
     * @return float|null
     */
    public function getSpecialPrice(): ?float
    {
        $price = $this->getData('price_tax_excluded');
        return $price !== null ? (float)$price : null;
    }

    /**
     * Get image URL
     *
     * @return string|null
     */
    public function getImage(): ?string
    {
        return $this->getData('image_url');
    }

    /**
     * Get small image URL
     *
     * @return string|null
     */
    public function getSmallImage(): ?string
    {
        return $this->getData('small_image_url');
    }

    /**
     * Get thumbnail URL
     *
     * @return string|null
     */
    public function getThumbnail(): ?string
    {
        return $this->getData('thumbnail_url');
    }

    /**
     * Get full product URL
     *
     * @return string|null
     */
    public function getProductUrl(): ?string
    {
        return $this->getData('product_url');
    }

    /**
     * Get brand
     *
     * @return string|null
     */
    public function getBrand(): ?string
    {
        return $this->getData('brand');
    }

    /**
     * Get search highlights
     *
     * @return array
     */
    public function getHighlights(): array
    {
        return $this->getData('highlights') ?? [];
    }

    /**
     * Check if product is in stock
     * Default to true since we don't have this from API
     *
     * @return bool
     */
    public function isInStock(): bool
    {
        return $this->getData('is_in_stock') ?? true;
    }

    /**
     * Check if product is salable
     * Default to true since we don't have this from API
     *
     * @return bool
     */
    public function isSalable(): bool
    {
        return $this->getData('is_salable') ?? true;
    }

    /**
     * Get status
     * Default to enabled (1)
     *
     * @return int
     */
    public function getStatus(): int
    {
        return (int)($this->getData('status') ?? 1);
    }

    /**
     * Get visibility
     * Default to visible in catalog and search (4)
     *
     * @return int
     */
    public function getVisibility(): int
    {
        return (int)($this->getData('visibility') ?? 4);
    }

    /**
     * Get store ID
     *
     * @return int|null
     */
    public function getStoreId(): ?int
    {
        $storeId = $this->getData('store_id');
        return $storeId !== null ? (int)$storeId : null;
    }

    /**
     * Get attribute value
     * Returns null for most attributes since they're not in API response
     *
     * @param string $attribute
     * @return mixed
     */
    public function getAttributeText($attribute)
    {
        return $this->getData($attribute);
    }

    /**
     * Get custom attribute
     * Returns null for most since they're not in API response
     *
     * @param string $attributeCode
     * @return mixed
     */
    public function getCustomAttribute($attributeCode)
    {
        return null;
    }

    /**
     * Get media gallery images
     * Returns empty array since we only have main image from API
     *
     * @return array
     */
    public function getMediaGalleryImages(): array
    {
        return [];
    }

    /**
     * Get category IDs
     * Returns empty array since categories aren't in API response
     *
     * @return array
     */
    public function getCategoryIds(): array
    {
        return [];
    }
}
