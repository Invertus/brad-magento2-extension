<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Model\Resolver;

use ArrayIterator;
use BradSearch\SearchGraphQl\Api\PriceCalculatorInterface;
use BradSearch\SearchGraphQl\Model\Api\Auth\ApiKeyValidator;
use BradSearch\SearchGraphQl\Model\Data\CalculatedPrice;
use BradSearch\SearchGraphQl\Model\Data\Money;
use BradSearch\SearchGraphQl\Model\Data\PriceTuple;
use BradSearch\SearchGraphQl\Model\Price\CalculatedPriceMapper;
use BradSearch\SearchGraphQl\Model\Resolver\BradProducts;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

/**
 * The bradProducts response brad-app reads, checked against the shared contract fixture
 * (_files/contract/products.json, a copy of the one in brad-search-php-sdk). Every key in the
 * fixture must be in the resolver's output with the same JSON type; extra keys are allowed.
 */
class BradProductsContractTest extends TestCase
{
    /**
     * Item fields brad-app requests that GraphQL fills from their own field resolvers (ProductFeatures,
     * SortPopularity*, Magento core), not from this resolver's output. Those resolvers read the item's
     * 'model', which testItemsCarryTheProductModel checks.
     */
    private const FIELD_RESOLVED_ITEM_FIELDS = [
        'id',
        'full_url',
        'is_in_stock',
        'allows_backorders',
        'short_description',
        'description',
        'attributes',
        'image_optimized',
        'categories',
        'stock_status',
        'sort_popularity',
        'sort_popularity_sales',
    ];

    public function testOutputMatchesTheContractFixture(): void
    {
        $expected = $this->fixture()['data']['bradProducts'];
        foreach (self::FIELD_RESOLVED_ITEM_FIELDS as $field) {
            unset($expected['items'][0][$field]);
        }

        $this->assertShape($expected, $this->resolve(), 'bradProducts');
    }

    public function testItemsCarryTheProductModel(): void
    {
        foreach ($this->resolve()['items'] as $item) {
            $this->assertInstanceOf(Product::class, $item['model']);
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function resolve(): array
    {
        $collection = $this->createStub(Collection::class);
        $collection->method('getIterator')->willReturn(new ArrayIterator([
            $this->product(['entity_id' => 101, 'type_id' => 'simple', 'sku' => 'MB-101', 'name' => 'Cordless Drill 18V']),
            $this->product(['entity_id' => 102, 'type_id' => 'configurable', 'sku' => 'MB-102', 'name' => 'Work Jacket']),
        ]));
        $collection->method('getSize')->willReturn(2);
        $collectionFactory = $this->createStub(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($collection);

        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $apiKeyValidator = $this->createStub(ApiKeyValidator::class);
        $apiKeyValidator->method('isValidRequest')->willReturn(true);

        $tuple = new PriceTuple(new Money(149.99, 'EUR'), new Money(119.99, 'EUR'), new Money(99.17, 'EUR'));
        $priceCalculator = $this->createStub(PriceCalculatorInterface::class);
        $priceCalculator->method('calculate')->willReturn(new CalculatedPrice($tuple, $tuple));

        $resolver = new BradProducts(
            $apiKeyValidator,
            $collectionFactory,
            $storeManager,
            $priceCalculator,
            new CalculatedPriceMapper(),
            $this->createStub(LoggerInterface::class)
        );

        return $resolver->resolve(
            $this->createStub(Field::class),
            null,
            $this->createStub(ResolveInfo::class),
            null,
            ['pageSize' => 2, 'currentPage' => 0]
        );
    }

    /**
     * @param array<string, mixed> $data
     */
    private function product(array $data): Product
    {
        $product = $this->createStub(Product::class);
        $product->method('getId')->willReturn($data['entity_id']);
        $product->method('getData')->willReturn($data);

        return $product;
    }

    /**
     * @return array<string, mixed>
     */
    private function fixture(): array
    {
        $json = file_get_contents(__DIR__ . '/../../_files/contract/products.json');

        return json_decode((string)$json, true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * @param mixed $expected
     * @param mixed $actual
     */
    private function assertShape($expected, $actual, string $path): void
    {
        $type = $this->jsonType($expected);
        $this->assertSame($type, $this->jsonType($actual), "{$path} has the wrong type");

        if ($type === 'object') {
            foreach ($expected as $key => $value) {
                $this->assertArrayHasKey($key, $actual, "{$path}.{$key} is missing");
                $this->assertShape($value, $actual[$key], "{$path}.{$key}");
            }
        } elseif ($type === 'list' && $expected !== []) {
            $this->assertNotEmpty($actual, "{$path} is empty");
            foreach ($actual as $index => $element) {
                $this->assertShape($expected[0], $element, "{$path}[{$index}]");
            }
        }
    }

    /**
     * @param mixed $value
     */
    private function jsonType($value): string
    {
        if (is_array($value)) {
            return array_is_list($value) ? 'list' : 'object';
        }
        if (is_int($value) || is_float($value)) {
            return 'number';
        }

        return gettype($value);
    }
}
