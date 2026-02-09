<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\ProductFeatures\Model\Resolver;

use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\Product\Media\Config as MediaConfig;
use Magento\Catalog\Model\ResourceModel\Product as ProductResource;
use Magento\Catalog\Model\View\Asset\PlaceholderFactory;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Framework\UrlInterface;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Resolver for image_optimized field on ProductInterface
 * Returns the product image URL with CDN optimization parameters
 */
class ImageOptimized implements ResolverInterface
{
    private const OPTIMIZATION_PARAMS = '?auto=webp&format=pjpg&width=840&height=375&fit=cover';

    /**
     * @var MediaConfig
     */
    private MediaConfig $mediaConfig;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var ProductResource
     */
    private ProductResource $productResource;

    /**
     * @var PlaceholderFactory
     */
    private PlaceholderFactory $placeholderFactory;

    /**
     * @param MediaConfig $mediaConfig
     * @param StoreManagerInterface $storeManager
     * @param ProductResource $productResource
     * @param PlaceholderFactory $placeholderFactory
     */
    public function __construct(
        MediaConfig $mediaConfig,
        StoreManagerInterface $storeManager,
        ProductResource $productResource,
        PlaceholderFactory $placeholderFactory
    ) {
        $this->mediaConfig = $mediaConfig;
        $this->storeManager = $storeManager;
        $this->productResource = $productResource;
        $this->placeholderFactory = $placeholderFactory;
    }

    /**
     * @inheritdoc
     */
    public function resolve(
        Field $field,
        $context,
        ResolveInfo $info,
        array $value = null,
        array $args = null
    ): ?string {
        if (!isset($value['model'])) {
            return null;
        }

        /** @var Product $product */
        $product = $value['model'];

        // Try to get image from already loaded data first
        $imagePath = $value['image'] ?? $product->getData('image');

        // If not loaded, fetch directly from database
        if ($imagePath === null) {
            $imagePath = $this->productResource->getAttributeRawValue(
                $product->getId(),
                'image',
                $this->storeManager->getStore()->getId()
            );
        }

        if (!$imagePath || $imagePath === 'no_selection') {
            return $this->getPlaceholderUrl();
        }

        $baseMediaUrl = $this->storeManager->getStore()->getBaseUrl(UrlInterface::URL_TYPE_MEDIA);
        $imageUrl = $baseMediaUrl . $this->mediaConfig->getBaseMediaPath() . $imagePath;

        return $imageUrl . self::OPTIMIZATION_PARAMS;
    }

    /**
     * Get placeholder image URL
     *
     * @return string
     */
    private function getPlaceholderUrl(): string
    {
        $placeholder = $this->placeholderFactory->create(['type' => 'image']);
        return $placeholder->getUrl() . self::OPTIMIZATION_PARAMS;
    }
}
