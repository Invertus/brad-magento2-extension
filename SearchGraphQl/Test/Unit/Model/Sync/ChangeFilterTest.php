<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Test\Unit\Model\Sync;

use BradSearch\SearchGraphQl\Api\ProductHashAttributeProviderInterface;
use BradSearch\SearchGraphQl\Model\Sync\ChangeFilter;
use BradSearch\SearchGraphQl\Model\Sync\ProductStateHasher;
use BradSearch\SearchGraphQl\Model\Sync\ProductStateReader;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

class ChangeFilterTest extends TestCase
{
    /** @var AdapterInterface&MockObject */
    private $connection;

    /** @var ProductStateReader&MockObject */
    private $reader;

    /** @var StoreManagerInterface&MockObject */
    private $storeManager;

    /** @var TimezoneInterface&MockObject */
    private $timezone;

    /** @var array<int, array<int, string>> store id => product id => stored hash */
    private array $stored = [];

    /** @var array<int, array<string, string>> product id => what the reader returns as its entity row */
    private array $catalog = [];

    protected function setUp(): void
    {
        $this->connection = $this->createMock(AdapterInterface::class);
        $select = $this->createMock(Select::class);
        $select->method($this->anything())->willReturnSelf();
        $this->connection->method('select')->willReturn($select);
        $this->connection->method('fetchAll')->willReturnCallback(function (): array {
            $rows = [];
            foreach ($this->stored as $storeId => $hashes) {
                foreach ($hashes as $productId => $hash) {
                    $rows[] = ['store_id' => (string)$storeId, 'product_id' => (string)$productId, 'hash' => $hash];
                }
            }
            return $rows;
        });

        $this->reader = $this->createMock(ProductStateReader::class);
        $this->reader->method('getAttributeIdsByType')->willReturn(['varchar' => [73]]);
        $this->reader->method('read')->willReturnCallback(function (array $productIds): array {
            return ['entity' => array_intersect_key($this->catalog, array_flip($productIds))];
        });

        $this->storeManager = $this->createMock(StoreManagerInterface::class);
        $this->storeManager->method('getStore')->willReturnCallback(function (int $storeId): Store {
            $store = $this->createMock(Store::class);
            $store->method('getWebsiteId')->willReturn($storeId === 1 ? 1 : 5);
            return $store;
        });

        $this->timezone = $this->createMock(TimezoneInterface::class);
        $this->timezone->method('scopeDate')->willReturn(new \DateTime('2026-11-11 10:00:00'));
    }

    public function testOnlyProductsWhoseHashMovedAreChanged(): void
    {
        $this->catalog = [10 => ['sku' => 'a'], 11 => ['sku' => 'b'], 12 => ['sku' => 'c']];
        $filter = $this->filter();
        $first = $filter->filter([1 => [10, 11, 12]]);
        $this->stored[1] = $first[1]['hashes'];

        $this->catalog[11] = ['sku' => 'b2'];
        $second = $filter->filter([1 => [10, 11, 12]]);

        $this->assertSame([11], $second[1]['changed']);
        $this->assertSame([11], array_keys($second[1]['hashes']));
        $this->assertNotSame($first[1]['hashes'][11], $second[1]['hashes'][11]);
    }

    public function testAProductWithNoStoredHashIsChanged(): void
    {
        $this->catalog = [10 => ['sku' => 'a']];

        $result = $this->filter()->filter([1 => [10, 404]]);

        $this->assertSame([10, 404], $result[1]['changed']);
    }

    public function testEachStoreIsComparedWithItsOwnStoredHashes(): void
    {
        $this->catalog = [10 => ['sku' => 'a'], 11 => ['sku' => 'b']];
        $filter = $this->filter();
        $this->stored[1] = $filter->filter([1 => [10, 11]])[1]['hashes'];

        $result = $filter->filter([1 => [10, 11], 2 => [11]]);

        $this->assertSame([], $result[1]['changed']);
        $this->assertSame([11], $result[2]['changed']);
    }

    public function testEachStoreHashesWithItsOwnWebsiteAndDate(): void
    {
        $calls = [];
        $hasher = $this->createMock(ProductStateHasher::class);
        $hasher->method('hash')->willReturnCallback(
            function (array $state, int $productId, int $storeId, int $websiteId, string $date) use (&$calls): string {
                $calls[] = [$productId, $storeId, $websiteId, $date];
                return 'h';
            }
        );

        $this->filter([], [], $hasher)->filter([1 => [10], 2 => [10]]);

        $this->assertSame([[10, 1, 1, '2026-11-11'], [10, 2, 5, '2026-11-11']], $calls);
    }

    public function testReadsInBatchesWithTheDefaultScopeAndEveryStore(): void
    {
        $calls = [];
        $this->reader = $this->createMock(ProductStateReader::class);
        $this->reader->method('getAttributeIdsByType')->willReturn(['varchar' => [73]]);
        $this->reader->method('read')->willReturnCallback(
            function (array $productIds, array $storeIds, array $attributeIds, array $ruleDates) use (&$calls): array {
                $calls[] = [count($productIds), max($productIds), $storeIds, $attributeIds, $ruleDates];
                return [];
            }
        );

        $this->filter()->filter([1 => range(1, 1001), 2 => [5]]);

        $context = [[0, 1, 2], ['varchar' => [73]], [1 => ['2026-11-11'], 5 => ['2026-11-11']]];
        $this->assertSame(
            [array_merge([1000, 1000], $context), array_merge([1, 1001], $context)],
            $calls
        );
    }

    public function testAttributeCodesComeFromConfigAndFromEveryProviderForEveryStore(): void
    {
        $provider = $this->createMock(ProductHashAttributeProviderInterface::class);
        $provider->method('getAttributeCodes')->willReturnMap([[1, ['weight', 'length_1']], [2, ['weight']]]);

        $reader = $this->createMock(ProductStateReader::class);
        $reader->expects($this->once())->method('getAttributeIdsByType')
            ->with(['name', 'status', 'weight', 'length_1'])
            ->willReturn([]);
        $reader->method('read')->willReturn([]);
        $this->reader = $reader;

        $this->filter(['name' => 'name', 'status' => 'status'], ['shipping' => $provider])
            ->filter([1 => [10], 2 => [10]]);
    }

    public function testProviderAttributesAreAlsoHashedAtDefaultScope(): void
    {
        $provider = $this->createMock(ProductHashAttributeProviderInterface::class);
        $provider->method('getAttributeCodes')->willReturn(['weight', 'tax_class_id']);
        $this->reader = $this->createMock(ProductStateReader::class);
        $this->reader->method('getAttributeIdsByType')->willReturn([]);
        $this->reader->method('read')->willReturn([]);
        $this->reader->expects($this->once())->method('getAttributeIds')
            ->with(['weight', 'tax_class_id'])
            ->willReturn([80, 132]);

        $defaultScopeIds = [];
        $hasher = $this->createMock(ProductStateHasher::class);
        $hasher->method('hash')->willReturnCallback(
            function (array $state, int $productId, int $storeId, int $websiteId, string $date, array $ids) use (&$defaultScopeIds): string {
                $defaultScopeIds = $ids;
                return 'h';
            }
        );

        $this->filter(['name' => 'name'], ['shipping' => $provider], $hasher)->filter([1 => [10]]);

        $this->assertSame([80, 132], $defaultScopeIds);
    }

    public function testWithoutProvidersNothingIsHashedAtDefaultScope(): void
    {
        $this->reader->expects($this->never())->method('getAttributeIds');

        $this->filter(['name' => 'name'])->filter([1 => [10]]);
    }

    public function testAProviderOfTheWrongTypeIsRejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $this->filter([], ['bad' => new \stdClass()]);
    }

    public function testStoreWritesTheHashesInBatches(): void
    {
        $hashes = [];
        foreach (range(1, 1001) as $productId) {
            $hashes[$productId] = md5((string)$productId);
        }

        $calls = [];
        $this->connection->method('insertOnDuplicate')->willReturnCallback(
            function (string $table, array $rows, array $fields) use (&$calls): int {
                $calls[] = [$table, $rows, $fields];
                return count($rows);
            }
        );

        $this->filter()->store($hashes, 7);

        $this->assertCount(2, $calls);
        $this->assertSame('bradsearch_product_state', $calls[0][0]);
        $this->assertCount(1000, $calls[0][1]);
        $this->assertSame(['store_id' => 7, 'product_id' => 1, 'hash' => md5('1')], $calls[0][1][0]);
        $this->assertSame(['hash'], $calls[0][2]);
        $this->assertSame([['store_id' => 7, 'product_id' => 1001, 'hash' => md5('1001')]], $calls[1][1]);
    }

    public function testStoringNothingWritesNothing(): void
    {
        $this->connection->expects($this->never())->method('insertOnDuplicate');

        $this->filter()->store([], 7);
    }

    /**
     * @param array<string, string> $codes
     * @param array<string, object> $providers
     * @param ProductStateHasher|null $hasher
     * @return ChangeFilter
     */
    private function filter(array $codes = [], array $providers = [], ?ProductStateHasher $hasher = null): ChangeFilter
    {
        $resource = $this->createMock(ResourceConnection::class);
        $resource->method('getConnection')->willReturn($this->connection);
        $resource->method('getTableName')->willReturnArgument(0);

        return new ChangeFilter(
            $resource,
            $this->reader,
            $hasher ?? new ProductStateHasher(),
            $this->storeManager,
            $this->timezone,
            $codes,
            $providers
        );
    }
}
