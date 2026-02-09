<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\ProductFeatures\Model\Resolver;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\ResolverInterface;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Magento\UrlRewrite\Model\UrlFinderInterface;
use Magento\UrlRewrite\Service\V1\Data\UrlRewrite;

/**
 * Resolver for full_url field on ProductInterface
 * Returns the complete absolute URL for the product, with PWA URL replacement if configured
 */
class FullUrl implements ResolverInterface
{
    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var UrlFinderInterface
     */
    private $urlFinder;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param UrlFinderInterface $urlFinder
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        UrlFinderInterface $urlFinder
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->urlFinder = $urlFinder;
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
    ) {
        if (!isset($value['model'])) {
            return null;
        }

        $product = $value['model'];
        $productUrl = $product->getProductUrl();

        // If getProductUrl() returns unfriendly URL, try to find SEO-friendly URL via rewrites
        if ($this->isUnfriendlyUrl($productUrl)) {
            $seoUrl = $this->getSeoFriendlyUrl($product);
            if ($seoUrl) {
                $productUrl = $seoUrl;
            }
        }

        // Replace Magento base URL with PWA frontend URL if configured
        $pwaUrl = $this->getPwaUrl();
        if ($pwaUrl) {
            $baseUrl = $this->getBaseUrl();
            $productUrl = str_replace($baseUrl, $pwaUrl, $productUrl);
        }

        return $productUrl;
    }

    /**
     * Check if URL is unfriendly (contains catalog/product/view)
     *
     * @param string $url
     * @return bool
     */
    private function isUnfriendlyUrl(string $url): bool
    {
        return strpos($url, 'catalog/product/view') !== false;
    }

    /**
     * Get SEO-friendly URL from url_rewrite table
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return string|null
     */
    private function getSeoFriendlyUrl($product): ?string
    {
        try {
            $storeId = (int)$this->storeManager->getStore()->getId();
            $productId = (int)$product->getId();

            $rewrites = $this->urlFinder->findAllByData([
                UrlRewrite::ENTITY_TYPE => 'product',
                UrlRewrite::ENTITY_ID => $productId,
                UrlRewrite::STORE_ID => $storeId,
                UrlRewrite::REDIRECT_TYPE => 0, // Only non-redirect rewrites
            ]);

            if (empty($rewrites)) {
                return null;
            }

            // Find the shortest URL (typically the base product URL without category path)
            $bestPath = null;
            $bestLen = PHP_INT_MAX;
            foreach ($rewrites as $rewrite) {
                $path = $rewrite->getRequestPath();
                $len = strlen($path);
                if ($len < $bestLen) {
                    $bestLen = $len;
                    $bestPath = $path;
                }
            }

            if ($bestPath) {
                $baseUrl = rtrim($this->storeManager->getStore()->getBaseUrl(), '/');
                return $baseUrl . '/' . $bestPath;
            }
        } catch (\Exception $e) {
            // Fall through to return null
        }

        return null;
    }

    /**
     * Get Magento base URL for current store
     *
     * @return string
     */
    private function getBaseUrl(): string
    {
        try {
            return $this->storeManager->getStore()->getBaseUrl();
        } catch (\Exception $e) {
            return '';
        }
    }

    /**
     * Get PWA frontend URL from configuration
     *
     * @return string|null
     */
    private function getPwaUrl(): ?string
    {
        return $this->scopeConfig->getValue(
            'mm_pwa_theme/general/url',
            ScopeInterface::SCOPE_STORE
        );
    }
}
