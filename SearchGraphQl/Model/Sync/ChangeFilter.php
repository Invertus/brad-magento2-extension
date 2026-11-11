<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\Sync;

use BradSearch\SearchGraphQl\Api\ProductHashAttributeProviderInterface;
use Magento\Framework\App\ResourceConnection;
use Magento\Framework\Stdlib\DateTime\TimezoneInterface;
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
     * @return array<int, array{changed: int[], hashes: array<int, string>}> store id => changed ids
     *         and their new hashes, to pass to store() once BradSearch has accepted them
     */
    public function filter(array $productIdsByStore): array
    {
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

        $attributeIds = $this->reader->getAttributeIdsByType($this->attributeCodes($storeIds));

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
            $stored = $this->loadStored($batch, $storeIds);

            foreach ($storeIds as $storeId) {
                [$websiteId, $date] = $contexts[$storeId];
                foreach ($batch as $productId) {
                    if (!isset($wanted[$storeId][$productId])) {
                        continue;
                    }

                    $hash = $this->hasher->hash($state, $productId, $storeId, $websiteId, $date);
                    if (($stored[$storeId][$productId] ?? null) !== $hash) {
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
     * @param int[] $storeIds
     * @return string[]
     */
    private function attributeCodes(array $storeIds): array
    {
        $codes = [$this->attributeCodes];
        foreach ($this->attributeProviders as $provider) {
            foreach ($storeIds as $storeId) {
                $codes[] = $provider->getAttributeCodes($storeId);
            }
        }

        return array_values(array_unique(array_merge(...$codes)));
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
