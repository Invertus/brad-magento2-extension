<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\ProductFeatures\Model\Resolver;

use BradSearch\ProductFeatures\Model\UrlRewriteDataLoader;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\ValueFactory;
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
     * Toggle to compare batch vs per-product URL rewrite loading.
     * When true: 1 batch query for all products.
     * When false: 1 query per product (original behavior).
     */
    private bool $useBatchUrlLoading = true;

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
     * @var UrlRewriteDataLoader
     */
    private UrlRewriteDataLoader $urlRewriteDataLoader;

    /**
     * @var ValueFactory
     */
    private ValueFactory $valueFactory;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param UrlFinderInterface $urlFinder
     * @param UrlRewriteDataLoader $urlRewriteDataLoader
     * @param ValueFactory $valueFactory
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        UrlFinderInterface $urlFinder,
        UrlRewriteDataLoader $urlRewriteDataLoader,
        ValueFactory $valueFactory
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->urlFinder = $urlFinder;
        $this->urlRewriteDataLoader = $urlRewriteDataLoader;
        $this->valueFactory = $valueFactory;
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

        if ($this->useBatchUrlLoading) {
            return $this->resolveBatch($product);
        }

        return $this->resolveLegacy($product);
    }

    /**
     * Batch URL resolution using deferred ValueFactory pattern
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return \Magento\Framework\GraphQl\Query\Resolver\Value
     */
    private function resolveBatch($product)
    {
        $productId = (int)$product->getId();
        $storeId = (int)$this->storeManager->getStore()->getId();

        $this->urlRewriteDataLoader->addToQueue($productId, $storeId);

        return $this->valueFactory->create(function () use ($product, $productId, $storeId) {
            $productUrl = $product->getProductUrl();

            if ($this->isUnfriendlyUrl($productUrl)) {
                $rewritePath = $this->urlRewriteDataLoader->getRewrite($productId, $storeId);
                if ($rewritePath) {
                    $baseUrl = rtrim($this->storeManager->getStore()->getBaseUrl(), '/');
                    $productUrl = $baseUrl . '/' . $rewritePath;
                }
            }

            return $this->applyPwaUrl($productUrl);
        });
    }

    /**
     * Original per-product URL resolution (legacy path)
     *
     * @param \Magento\Catalog\Model\Product $product
     * @return string|null
     */
    private function resolveLegacy($product): ?string
    {
        $productUrl = $product->getProductUrl();

        if ($this->isUnfriendlyUrl($productUrl)) {
            $seoUrl = $this->getSeoFriendlyUrl($product);
            if ($seoUrl) {
                $productUrl = $seoUrl;
            }
        }

        return $this->applyPwaUrl($productUrl);
    }

    /**
     * Replace Magento base URL with PWA frontend URL if configured
     *
     * @param string $productUrl
     * @return string
     */
    private function applyPwaUrl(string $productUrl): string
    {
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
     * Get SEO-friendly URL from url_rewrite table (legacy per-product query)
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
