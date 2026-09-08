<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Model\Resolver;

use ArrayIterator;
use BradSearch\SearchGraphQl\Api\Data\CalculatedPriceInterface;
use BradSearch\SearchGraphQl\Api\PriceCalculatorInterface;
use BradSearch\SearchGraphQl\Model\Api\Auth\ApiKeyValidator;
use BradSearch\SearchGraphQl\Model\Price\CalculatedPriceMapper;
use BradSearch\SearchGraphQl\Model\Resolver\BradProducts;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use RuntimeException;

/**
 * The enumeration contract the sync relies on: stable page order, an honest total, and
 * products the page could not build reported rather than silently dropped.
 */
class BradProductsResolveTest extends TestCase
{
    private BradProducts $resolver;
    private Collection&MockObject $collection;
    private PriceCalculatorInterface&MockObject $priceCalculator;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        $this->collection = $this->createMock(Collection::class);
        $collectionFactory = $this->createMock(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($this->collection);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $apiKeyValidator = $this->createMock(ApiKeyValidator::class);
        $apiKeyValidator->method('isValidRequest')->willReturn(true);

        $this->priceCalculator = $this->createMock(PriceCalculatorInterface::class);
        $priceMapper = $this->createMock(CalculatedPriceMapper::class);
        $priceMapper->method('toGraphQlArray')->willReturn(['final_price' => ['value' => 1.0]]);

        $this->logger = $this->createMock(LoggerInterface::class);

        $this->resolver = new BradProducts(
            $apiKeyValidator,
            $collectionFactory,
            $storeManager,
            $this->priceCalculator,
            $priceMapper,
            $this->logger
        );
    }

    public function testOrdersThePageByEntityIdAscending(): void
    {
        $this->collection
            ->expects($this->once())
            ->method('addAttributeToSort')
            ->with('entity_id', Collection::SORT_ORDER_ASC);
        $this->collectionYields([]);

        $this->resolve();
    }

    public function testReadsTheTotalAfterThePageHasLoaded(): void
    {
        // A count taken before load misses whatever collection plugins add on load, overstating the
        // catalog and sending the sync to fetch empty pages past the real end.
        $calls = [];
        $this->collection->method('getIterator')->willReturnCallback(
            function () use (&$calls) {
                $calls[] = 'load';
                return new ArrayIterator([]);
            }
        );
        $this->collection->method('getSize')->willReturnCallback(
            function () use (&$calls) {
                $calls[] = 'count';
                return 7;
            }
        );

        $result = $this->resolve(['pageSize' => 2, 'currentPage' => 0]);

        $this->assertSame(['load', 'count'], $calls);
        $this->assertSame(7, $result['total_count']);
        $this->assertSame(4, $result['page_info']['total_pages']);
    }

    public function testAProductThatCannotBePricedIsLeftOutAndCounted(): void
    {
        $good = $this->product(11);
        $bad = $this->product(12);
        $this->priceCalculator->method('calculate')->willReturnCallback(
            function (Product $product) use ($bad) {
                if ($product === $bad) {
                    throw new RuntimeException('no price for you');
                }
                return $this->createMock(CalculatedPriceInterface::class);
            }
        );
        $this->logger
            ->expects($this->once())
            ->method('error')
            ->with(
                $this->anything(),
                $this->callback(fn (array $context) => $context['entity_id'] === 12 && $context['error'] === 'no price for you')
            );
        $this->collectionYields([$good, $bad]);

        $result = $this->resolve();

        $this->assertCount(1, $result['items']);
        $this->assertSame(11, $result['items'][0]['entity_id']);
        $this->assertSame(1, $result['skipped_count']);
        $this->assertSame(2, $result['total_count'], 'the skipped product is still part of the total');
    }

    public function testACleanPageReportsNothingSkipped(): void
    {
        $this->priceCalculator->method('calculate')->willReturn(null);
        $this->logger->expects($this->never())->method('error');
        $this->collectionYields([$this->product(1), $this->product(2)]);

        $result = $this->resolve();

        $this->assertCount(2, $result['items']);
        $this->assertSame(0, $result['skipped_count']);
        $this->assertNull($result['items'][0]['calculated_price']);
    }

    /**
     * @param array<string, mixed>|null $args
     * @return array<string, mixed>
     */
    private function resolve(?array $args = null): array
    {
        return $this->resolver->resolve(
            $this->createMock(Field::class),
            null,
            $this->createMock(ResolveInfo::class),
            null,
            $args ?? ['pageSize' => 300, 'currentPage' => 0]
        );
    }

    /**
     * @param Product[] $products
     */
    private function collectionYields(array $products): void
    {
        $this->collection->method('getIterator')->willReturn(new ArrayIterator($products));
        $this->collection->method('getSize')->willReturn(count($products));
    }

    private function product(int $entityId): Product&MockObject
    {
        $product = $this->createMock(Product::class);
        $product->method('getId')->willReturn($entityId);
        $product->method('getData')->willReturn(['entity_id' => $entityId, 'sku' => "sku-{$entityId}"]);

        return $product;
    }
}
