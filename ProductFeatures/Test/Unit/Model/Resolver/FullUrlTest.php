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
use PHPUnit\Framework\TestCase;

class FullUrlTest extends TestCase
{
    private const STORE_ID = 2;
    private const PRODUCT_ID = 84009;

    public function testSecondStoreViewUsesItsOwnBaseUrl(): void
    {
        $url = $this->resolve(
            'https://shop.test/ru/',
            'polishing-machine.html',
            'https://shop.test/polishing-machine.html'
        );

        $this->assertSame('https://shop.test/ru/polishing-machine.html', $url);
    }

    public function testSingleStoreKeepsTheSameUrl(): void
    {
        $url = $this->resolve(
            'https://shop.test/',
            'polishing-machine.html',
            'https://shop.test/polishing-machine.html'
        );

        $this->assertSame('https://shop.test/polishing-machine.html', $url);
    }

    public function testProductWithoutRewriteKeepsMagentoUrl(): void
    {
        $url = $this->resolve(
            'https://shop.test/ru/',
            null,
            'https://shop.test/ru/catalog/product/view/id/84009/'
        );

        $this->assertSame('https://shop.test/ru/catalog/product/view/id/84009/', $url);
    }

    public function testPwaUrlReplacesTheStoreBaseUrl(): void
    {
        $url = $this->resolve(
            'https://shop.test/ru/',
            'polishing-machine.html',
            'https://shop.test/polishing-machine.html',
            'https://pwa.test/ru/'
        );

        $this->assertSame('https://pwa.test/ru/polishing-machine.html', $url);
    }

    private function resolve(
        string $storeBaseUrl,
        ?string $rewritePath,
        string $magentoProductUrl,
        ?string $pwaUrl = null
    ): string {
        $store = $this->createStub(Store::class);
        $store->method('getBaseUrl')->willReturn($storeBaseUrl);
        $store->method('getId')->willReturn(self::STORE_ID);

        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $scopeConfig = $this->createStub(ScopeConfigInterface::class);
        $scopeConfig->method('getValue')->willReturn($pwaUrl);

        $rewriteLoader = $this->createMock(UrlRewriteDataLoader::class);
        $rewriteLoader->expects($this->once())
            ->method('addToQueue')
            ->with(self::PRODUCT_ID, self::STORE_ID);
        $rewriteLoader->method('getRewrite')
            ->with(self::PRODUCT_ID, self::STORE_ID)
            ->willReturn($rewritePath);

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
