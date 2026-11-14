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
use BradSearch\SearchGraphQl\Model\Sync\FetchRecorder;
use Magento\Catalog\Model\Product;
use Magento\Catalog\Model\ResourceModel\Product\Collection;
use Magento\Catalog\Model\ResourceModel\Product\CollectionFactory;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\GraphQl\Config\Element\Field;
use Magento\Framework\GraphQl\Schema\Type\ResolveInfo;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\MockObject\Stub;
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
    private PriceCalculatorInterface&Stub $priceCalculator;
    private LoggerInterface&MockObject $logger;
    private FetchRecorder&MockObject $fetchRecorder;

    protected function setUp(): void
    {
        $this->collection = $this->createMock(Collection::class);
        $collectionFactory = $this->createStub(CollectionFactory::class);
        $collectionFactory->method('create')->willReturn($this->collection);

        $store = $this->createStub(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createStub(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        $apiKeyValidator = $this->createStub(ApiKeyValidator::class);
        $apiKeyValidator->method('isValidRequest')->willReturn(true);

        $this->priceCalculator = $this->createStub(PriceCalculatorInterface::class);
        $priceMapper = $this->createStub(CalculatedPriceMapper::class);
        $priceMapper->method('toGraphQlArray')->willReturn(['final_price' => ['value' => 1.0]]);

        $this->logger = $this->createMock(LoggerInterface::class);
        $this->fetchRecorder = $this->createMock(FetchRecorder::class);

        $this->resolver = new BradProducts(
            $apiKeyValidator,
            $collectionFactory,
            $storeManager,
            $this->priceCalculator,
            $priceMapper,
            $this->logger,
            $this->fetchRecorder
        );
    }

    public function testTheSyncCursorIsReadBeforeThePageLoads(): void
    {
        $calls = [];
        $this->fetchRecorder->method('cursor')->willReturnCallback(function () use (&$calls): ?int {
            $calls[] = 'cursor';
            return 400;
        });
        $this->collection->method('getIterator')->willReturnCallback(function () use (&$calls) {
            $calls[] = 'load';
            return new ArrayIterator([]);
        });

        $this->resolve();

        $this->assertSame(['cursor', 'load'], $calls);
    }

    public function testNamedProductsAreRecordedWhetherOrNotTheyCameBack(): void
    {
        // BradSearch reads a named product it does not get back as removed.
        $this->fetchRecorder->method('cursor')->with(1)->willReturn(400);
        $this->priceCalculator->method('calculate')->willReturn(null);
        $this->collectionYields([$this->product(11)]);
        $this->fetchRecorder->expects($this->never())->method('recordRange');
        $this->fetchRecorder->expects($this->once())->method('record')
            ->with(1, 400, $this->callback(function (array $ids): bool {
                return array_values(array_unique($ids)) === [11, 12, 13];
            }));

        $this->resolve(['pageSize' => 300, 'currentPage' => 0, 'filter' => ['entity_id' => ['in' => ['11', '12', '13']]]]);
    }

    public function testAFullFirstPageStandsForTheIdsUpToItsLastProduct(): void
    {
        $this->fetchRecorder->method('cursor')->willReturn(400);
        $this->priceCalculator->method('calculate')->willReturn(null);
        $this->collectionYields([$this->product(5), $this->product(9)]);
        $this->fetchRecorder->expects($this->once())->method('recordRange')->with(1, 400, 0, 9);

        $this->resolve(['pageSize' => 2, 'currentPage' => 0]);
    }

    public function testALaterPageStartsAtThePreviousPagesLastProductAndTheLastPageHasNoEnd(): void
    {
        $this->fetchRecorder->method('cursor')->willReturn(400);
        $this->priceCalculator->method('calculate')->willReturn(null);
        $this->collectionYields([$this->product(5)]);
        $limits = [];
        $select = $this->createMock(Select::class);
        $select->method('limit')->willReturnCallback(function ($count, $offset) use ($select, &$limits) {
            $limits[] = [$count, $offset];
            return $select;
        });
        $select->method($this->logicalNot($this->equalTo('limit')))->willReturnSelf();
        $this->collection->method('getSelect')->willReturn($select);
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchOne')->willReturn('4');
        $this->collection->method('getConnection')->willReturn($connection);
        $this->fetchRecorder->expects($this->once())->method('recordRange')->with(1, 400, 4, null);

        $this->resolve(['pageSize' => 2, 'currentPage' => 1]);

        $this->assertSame([[1, 1]], $limits);
    }

    public function testAPagePastTheEndStartsAtTheCollectionsLastProduct(): void
    {
        $this->fetchRecorder->method('cursor')->willReturn(400);
        $this->collectionYields([]);
        $select = $this->createMock(Select::class);
        $select->method($this->anything())->willReturnSelf();
        $this->collection->method('getSelect')->willReturn($select);
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('fetchOne')->willReturnOnConsecutiveCalls(false, '77');
        $this->collection->method('getConnection')->willReturn($connection);
        $this->fetchRecorder->expects($this->once())->method('recordRange')->with(1, 400, 77, null);

        $this->resolve(['pageSize' => 2, 'currentPage' => 9]);
    }

    public function testNothingIsRecordedWithoutALiveSyncCursor(): void
    {
        $this->fetchRecorder->method('cursor')->willReturn(null);
        $this->collectionYields([]);
        $this->fetchRecorder->expects($this->never())->method('record');
        $this->fetchRecorder->expects($this->never())->method('recordRange');

        $this->resolve();
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
            $this->createStub(Field::class),
            null,
            $this->createStub(ResolveInfo::class),
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

    private function product(int $entityId): Product&Stub
    {
        $product = $this->createStub(Product::class);
        $product->method('getId')->willReturn($entityId);
        $product->method('getData')->willReturn(['entity_id' => $entityId, 'sku' => "sku-{$entityId}"]);

        return $product;
    }
}
