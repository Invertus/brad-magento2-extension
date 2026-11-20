<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\Sync;

use BradSearch\SearchGraphQl\Api\ProductHashAttributeProviderInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;

/**
 * Keeps only the products whose indexed data changed since BradSearch was last told about them.
 *
 * The changelog says a product row was written, not that anything BradSearch indexes moved:
 * bulk jobs rewrite whole catalogs every few hours. Each product gets a hash per store view of
 * what BradSearch indexes; it is sent only when that hash differs from the one stored after the
 * last successful notification.
 */
class ChangeFilter
{
    private const STATE_TABLE = 'bradsearch_product_state';

    private const BATCH_SIZE = 1000;

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resource;

    /**
     * @var ProductStateReader
     */
    private ProductStateReader $reader;

    /**
     * @var ProductStateHasher
     */
    private ProductStateHasher $hasher;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var TimezoneInterface
     */
    private TimezoneInterface $timezone;

    /**
     * @var string[]
     */
    private array $attributeCodes;

    /**
     * @var ProductHashAttributeProviderInterface[]
     */
    private array $attributeProviders;

    /**
     * @param ResourceConnection $resource
     * @param ProductStateReader $reader
     * @param ProductStateHasher $hasher
     * @param StoreManagerInterface $storeManager
     * @param TimezoneInterface $timezone
     * @param string[] $attributeCodes Attributes the sync reads whatever their search flags
     * @param ProductHashAttributeProviderInterface[] $attributeProviders
     * @throws \InvalidArgumentException
     */
    public function __construct(
        ResourceConnection $resource,
        ProductStateReader $reader,
        ProductStateHasher $hasher,
        StoreManagerInterface $storeManager,
        TimezoneInterface $timezone,
        array $attributeCodes = [],
        array $attributeProviders = []
    ) {
        foreach ($attributeProviders as $name => $provider) {
            if (!$provider instanceof ProductHashAttributeProviderInterface) {
                throw new \InvalidArgumentException(sprintf(
                    'Attribute provider "%s" must implement %s',
                    $name,
                    ProductHashAttributeProviderInterface::class
                ));
            }
        }

        $this->resource = $resource;
        $this->reader = $reader;
        $this->hasher = $hasher;
        $this->storeManager = $storeManager;
        $this->timezone = $timezone;
        $this->attributeCodes = array_values($attributeCodes);
        $this->attributeProviders = array_values($attributeProviders);
    }

    /**
     * Find the products whose hash moved, for several store views in one pass over the catalog.
     *
     * @param array<int, int[]> $productIdsByStore store id => changelog product ids
     * @param array<int, int[]> $forcedByStore store id => product ids to count as changed whatever their hash
     * @return array<int, array{changed: int[], hashes: array<int, string>}> store id => changed ids
     *         and their new hashes, to pass to store() once BradSearch has accepted them
     */
    public function filter(array $productIdsByStore, array $forcedByStore = []): array
    {
        $forced = [];
        foreach ($forcedByStore as $storeId => $productIds) {
            $forced[(int)$storeId] = array_flip(array_map('intval', $productIds));
        }

        $storeIds = array_map('intval', array_keys($productIdsByStore));
        $contexts = [];
        $ruleDates = [];
        foreach ($storeIds as $storeId) {
            $websiteId = (int)$this->storeManager->getStore($storeId)->getWebsiteId();
            $date = $this->timezone->scopeDate($storeId)->format('Y-m-d');
            $contexts[$storeId] = [$websiteId, $date];
            $ruleDates[$websiteId][] = $date;
        }
        $ruleDates = array_map(static function (array $dates): array {
            return array_values(array_unique($dates));
        }, $ruleDates);

        $providerCodes = $this->providerCodes($storeIds);
        $attributeIds = $this->reader->getAttributeIdsByType(
            array_values(array_unique(array_merge($this->attributeCodes, $providerCodes)))
        );
        // A price calculator may read these from the default-scope product, so a default change counts
        // even when the store view has its own value.
        $defaultScopeIds = empty($providerCodes) ? [] : $this->reader->getAttributeIds($providerCodes);

        $wanted = [];
        $allIds = [];
        $result = [];
        foreach ($productIdsByStore as $storeId => $productIds) {
            $productIds = array_map('intval', $productIds);
            $wanted[(int)$storeId] = array_flip($productIds);
            $allIds[] = $productIds;
            $result[(int)$storeId] = ['changed' => [], 'hashes' => []];
        }
        $allIds = array_values(array_unique(array_merge([], ...$allIds)));

        foreach (array_chunk($allIds, self::BATCH_SIZE) as $batch) {
            $state = $this->reader->read($batch, array_merge([0], $storeIds), $attributeIds, $ruleDates);
            $state['specialActive'] = $this->specialPricesActive($state['specialDates'] ?? [], $storeIds);
            $stored = $this->loadStored($batch, $storeIds);

            foreach ($storeIds as $storeId) {
                [$websiteId, $date] = $contexts[$storeId];
                foreach ($batch as $productId) {
                    if (!isset($wanted[$storeId][$productId])) {
                        continue;
                    }

                    $hash = $this->hasher->hash($state, $productId, $storeId, $websiteId, $date, $defaultScopeIds);
                    if (($stored[$storeId][$productId] ?? null) !== $hash || isset($forced[$storeId][$productId])) {
                        $result[$storeId]['changed'][] = $productId;
                        $result[$storeId]['hashes'][$productId] = $hash;
                    }
                }
            }
        }

        return $result;
    }

    /**
     * Record the hashes BradSearch has now been told about.
     *
     * @param array<int, string> $hashes product id => hash, from filter()
     * @param int $storeId
     * @return void
     */
    public function store(array $hashes, int $storeId): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::STATE_TABLE);

        foreach (array_chunk($hashes, self::BATCH_SIZE, true) as $batch) {
            $rows = [];
            foreach ($batch as $productId => $hash) {
                $rows[] = ['store_id' => $storeId, 'product_id' => (int)$productId, 'hash' => $hash];
            }
            $connection->insertOnDuplicate($table, $rows, ['hash']);
        }
    }

    /**
     * Forget what BradSearch was told about these products, so their next change is sent whatever its hash.
     * For products sent without the filter: BradSearch may now hold something other than the stored hash.
     *
     * @param int[] $productIds
     * @param int $storeId
     * @return void
     */
    public function forget(array $productIds, int $storeId): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::STATE_TABLE);
        // No table, no stored hashes: the filter fails and everything is sent anyway.
        if (!$connection->isTableExists($table)) {
            return;
        }

        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        foreach (array_chunk($productIds, self::BATCH_SIZE) as $batch) {
            $connection->delete($table, ['store_id = ?' => $storeId, 'product_id IN (?)' => $batch]);
        }
    }

    /**
     * Whether each product's special price applies now, the way Magento's special price decides it: the
     * store view's own dates or the default ones, against today in the admin scope.
     *
     * @param array<int, array<string, array<int, string|null>>> $dates From the reader
     * @param int[] $storeIds
     * @return array<int, array<int, bool>> product id => store id => applies now
     */
    private function specialPricesActive(array $dates, array $storeIds): array
    {
        $active = [];
        foreach ($dates as $productId => $parts) {
            foreach ($storeIds as $storeId) {
                $from = $this->storeValue($parts['from'] ?? [], $storeId);
                $to = $this->storeValue($parts['to'] ?? [], $storeId);
                if ($from === null && $to === null) {
                    continue;
                }
                $active[$productId][$storeId] = $this->timezone->isScopeDateInInterval(Store::ADMIN_CODE, $from, $to);
            }
        }

        return $active;
    }

    /**
     * @param array<int, string|null> $values store id => value
     * @param int $storeId
     * @return string|null The store view's value, even an empty one, else the default
     */
    private function storeValue(array $values, int $storeId): ?string
    {
        return array_key_exists($storeId, $values) ? $values[$storeId] : ($values[0] ?? null);
    }

    /**
     * @param int[] $storeIds
     * @return string[]
     */
    private function providerCodes(array $storeIds): array
    {
        $codes = [];
        foreach ($this->attributeProviders as $provider) {
            foreach ($storeIds as $storeId) {
                $codes[] = $provider->getAttributeCodes($storeId);
            }
        }

        return array_values(array_unique(array_merge([], ...$codes)));
    }

    /**
     * @param int[] $productIds
     * @param int[] $storeIds
     * @return array<int, array<int, string>> store id => product id => hash
     */
    private function loadStored(array $productIds, array $storeIds): array
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::STATE_TABLE);

        $stored = [];
        // One query per store: with both columns as IN lists MariaDB scanned the whole table.
        foreach ($storeIds as $storeId) {
            $select = $connection->select()
                ->from($table, ['store_id', 'product_id', 'hash'])
                ->where('store_id = ?', $storeId)
                ->where('product_id IN (?)', $productIds);

            foreach ($connection->fetchAll($select) as $row) {
                $stored[(int)$row['store_id']][(int)$row['product_id']] = (string)$row['hash'];
            }
        }

        return $stored;
    }
}
