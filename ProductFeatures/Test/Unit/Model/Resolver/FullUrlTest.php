<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\ProductFeatures\Test\Unit\Model\Resolver;

use BradSearch\ProductFeatures\Model\Resolver\FullUrl;
use BradSearch\ProductFeatures\Model\UrlRewriteDataLoader;
use Magento\Catalog\Model\Product;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Query\Resolver\Value;
use Magento\Framework\GraphQl\Query\Resolver\ValueFactory;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use Magento\Store\Model\Website;
use PHPUnit\Framework\TestCase;

class FullUrlTest extends TestCase
{
    private const STORE_ID = 2;
    private const DEFAULT_STORE_ID = 1;
    private const PRODUCT_ID = 84009;
    private const INHERITED = 'inherited';

    public function testWithoutPwaUrlKeepsMagentoUrl(): void
    {
        $url = $this->resolve('https://shop.test/ru/', 'https://shop.test/', 'https://shop.test/ru/drill.html', null);

        $this->assertSame('https://shop.test/ru/drill.html', $url);
    }

    public function testDefaultStoreGetsPwaHost(): void
    {
        $url = $this->resolve('https://shop.test/', 'https://shop.test/', 'https://shop.test/drill.html', 'https://pwa.test/');

        $this->assertSame('https://pwa.test/drill.html', $url);
    }

    public function testSecondStoreViewKeepsItsPathWithSharedPwaUrl(): void
    {
        $url = $this->resolve('https://shop.test/ru/', 'https://shop.test/', 'https://shop.test/ru/drill.html', 'https://pwa.test/');

        $this->assertSame('https://pwa.test/ru/drill.html', $url);
    }

    public function testSecondStoreViewWithOwnPwaUrlIsNotDoubled(): void
    {
        $url = $this->resolve(
            'https://shop.test/ru/',
            'https://shop.test/',
            'https://shop.test/ru/drill.html',
            'https://pwa.test/ru/',
            null,
            'https://pwa.test/'
        );

        $this->assertSame('https://pwa.test/ru/drill.html', $url);
    }

    public function testSecondStoreViewWithOwnPwaUrlOnOtherHostKeepsPlainReplace(): void
    {
        $url = $this->resolve(
            'https://shop.test/ru/',
            'https://shop.test/',
            'https://shop.test/ru/drill.html',
            'https://ru.pwa.test/',
            null,
            'https://pwa.test/'
        );

        $this->assertSame('https://ru.pwa.test/drill.html', $url);
    }

    public function testSharedPwaUrlEndingLikeStorePathStillGetsStorePath(): void
    {
        $url = $this->resolve('https://shop.test/ru/', 'https://shop.test/', 'https://shop.test/ru/drill.html', 'https://pwa.test/guru/');

        $this->assertSame('https://pwa.test/guru/ru/drill.html', $url);
    }

    public function testSharedPwaUrlWithoutTrailingSlashKeepsStorePath(): void
    {
        $url = $this->resolve('https://shop.test/ru/', 'https://shop.test/', 'https://shop.test/ru/drill.html', 'https://pwa.test');

        $this->assertSame('https://pwa.test/ru/drill.html', $url);
    }

    public function testStoreOnOtherHostGetsPlainReplace(): void
    {
        $url = $this->resolve('https://ru.shop.test/', 'https://shop.test/', 'https://ru.shop.test/drill.html', 'https://pwa.test/');

        $this->assertSame('https://pwa.test/drill.html', $url);
    }

    public function testStoreCodeUrlsGetPlainReplace(): void
    {
        $url = $this->resolve('https://shop.test/ru_store/', 'https://shop.test/lv_store/', 'https://shop.test/ru_store/drill.html', 'https://pwa.test/');

        $this->assertSame('https://pwa.test/drill.html', $url);
    }

    public function testMissingDefaultStoreGetsPlainReplace(): void
    {
        $url = $this->resolve('https://shop.test/ru/', null, 'https://shop.test/ru/drill.html', 'https://pwa.test/');

        $this->assertSame('https://pwa.test/drill.html', $url);
    }

    public function testUnfriendlyUrlUsesRewriteAndStorePath(): void
    {
        $url = $this->resolve(
            'https://shop.test/ru/',
            'https://shop.test/',
            'https://shop.test/ru/catalog/product/view/id/84009/',
            'https://pwa.test/',
            'drill.html'
        );

        $this->assertSame('https://pwa.test/ru/drill.html', $url);
    }

    private function resolve(
        string $storeBaseUrl,
        ?string $defaultStoreBaseUrl,
        string $magentoProductUrl,
        ?string $pwaUrl,
        ?string $rewritePath = null,
        ?string $defaultStorePwaUrl = self::INHERITED
    ): string {
        $defaultStore = null;
        if ($defaultStoreBaseUrl !== null) {
            $defaultStore = $this->createStub(Store::class);
            $defaultStore->method('getBaseUrl')->willReturn($defaultStoreBaseUrl);
            $defaultStore->method('getId')->willReturn(self::DEFAULT_STORE_ID);
        }
        if ($defaultStorePwaUrl === self::INHERITED) {
            $defaultStorePwaUrl = $pwaUrl;
        }

        $website = $this->createStub(Website::class);
        $website->method('getDefaultStore')->willReturn($defaultStore);

        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn($storeBaseUrl);
        $store->method('getId')->willReturn(self::STORE_ID);
        $store->method('getWebsite')->willReturn($website);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturnCallback(
            function (string $path, string $scope, $storeId = null) use ($pwaUrl, $defaultStorePwaUrl) {
                return $storeId === self::DEFAULT_STORE_ID ? $defaultStorePwaUrl : $pwaUrl;
            }
        );

        $rewriteLoader = $this->createStub(UrlRewriteDataLoader::class);
        $rewriteLoader->method('getRewrite')->willReturn($rewritePath);

        $deferred = null;
        $valueFactory = $this->createStub(ValueFactory::class);
        $valueFactory->method('create')->willReturnCallback(
            function (callable $callback) use (&$deferred) {
                $deferred = $callback;
                return $this->createStub(Value::class);
            }
        );

        $product = $this->createStub(Product::class);
        $product->method('getId')->willReturn(self::PRODUCT_ID);
        $product->method('getProductUrl')->willReturn($magentoProductUrl);

        $resolver = new FullUrl($scopeConfig, $storeManager, $rewriteLoader, $valueFactory);
        $resolver->resolve(
            $this->createStub(Field::class),
            $this->contextForStore($store),
            $this->createStub(ResolveInfo::class),
            ['model' => $product]
        );

        $this->assertIsCallable($deferred);

        return $deferred();
    }

    private function contextForStore(Store $store): object
    {
        return new class ($store) {
            private Store $store;

            public function __construct(Store $store)
            {
                $this->store = $store;
            }

            public function getExtensionAttributes(): object
            {
                $store = $this->store;

                return new class ($store) {
                    private Store $store;

                    public function __construct(Store $store)
                    {
                        $this->store = $store;
                    }

                    public function getStore(): Store
                    {
                        return $this->store;
                    }
                };
            }
        };
    }
}
