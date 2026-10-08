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
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Resolver for full_url field on ProductInterface
 * Returns the complete absolute URL for the product, with PWA URL replacement if configured.
 * Uses batch loading via UrlRewriteDataLoader to avoid N+1 queries.
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
     * @param UrlRewriteDataLoader $urlRewriteDataLoader
     * @param ValueFactory $valueFactory
     */
    public function __construct(
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        UrlRewriteDataLoader $urlRewriteDataLoader,
        ValueFactory $valueFactory
    ) {
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
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
        $productId = (int)$product->getId();
        $storeId = (int)$context->getExtensionAttributes()->getStore()->getId();

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
            $productUrl = str_replace($baseUrl, $this->addStorePath($pwaUrl, $baseUrl), $productUrl);
        }

        return $productUrl;
    }

    /**
     * Keep the store view's own path (e.g. "ru/") when the PWA URL is shared with the website's default store
     *
     * @param string $pwaUrl
     * @param string $baseUrl
     * @return string
     */
    private function addStorePath(string $pwaUrl, string $baseUrl): string
    {
        $defaultStore = $this->getDefaultStore();
        if ($defaultStore === null || $this->getPwaUrl((int)$defaultStore->getId()) !== $pwaUrl) {
            return $pwaUrl;
        }

        $defaultBaseUrl = (string)$defaultStore->getBaseUrl();
        if ($defaultBaseUrl === '' || $baseUrl === $defaultBaseUrl || strpos($baseUrl, $defaultBaseUrl) !== 0) {
            return $pwaUrl;
        }

        return rtrim($pwaUrl, '/') . '/' . substr($baseUrl, strlen($defaultBaseUrl));
    }

    /**
     * Get the current website's default store
     *
     * @return Store|null
     */
    private function getDefaultStore(): ?Store
    {
        try {
            $store = $this->storeManager->getStore();
            if (!$store instanceof Store) {
                return null;
            }
            $website = $store->getWebsite();
            $defaultStore = $website ? $website->getDefaultStore() : null;
            return $defaultStore instanceof Store ? $defaultStore : null;
        } catch (\Throwable $e) {
            return null;
        }
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
     * @param int|null $storeId
     * @return string|null
     */
    private function getPwaUrl(?int $storeId = null): ?string
    {
        return $this->scopeConfig->getValue(
            'mm_pwa_theme/general/url',
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
