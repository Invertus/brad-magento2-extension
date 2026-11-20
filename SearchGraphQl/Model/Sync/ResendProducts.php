<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\Sync;

use Magento\Framework\App\ResourceConnection;

/**
 * Products BradSearch fetched while they had a change the sync had not handled yet.
 *
 * The stored hash describes the product as it was when the sync last sent it, but BradSearch reads the
 * product later. A change undone between that read and the next sync run leaves the hash equal and
 * BradSearch holding the in-between state, so these products are sent once more whatever their hash.
 * Each mark has its own token, so a run removes only the marks it read and sent.
 */
class ResendProducts
{
    private const TABLE = 'bradsearch_resend_product';

    private const BATCH_SIZE = 1000;

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resource;

    /**
     * @param ResourceConnection $resource
     */
    public function __construct(ResourceConnection $resource)
    {
        $this->resource = $resource;
    }

    /**
     * False until setup:upgrade has created the table
     *
     * @return bool
     */
    public function isAvailable(): bool
    {
        return $this->resource->getConnection()->isTableExists($this->resource->getTableName(self::TABLE));
    }

    /**
     * @param int $storeId
     * @return array<int, string> product id => token of its mark
     */
    public function forStore(int $storeId): array
    {
        $connection = $this->resource->getConnection();
        $marks = [];
        foreach ($connection->fetchPairs(
            $connection->select()
                ->from($this->resource->getTableName(self::TABLE), ['product_id', 'token'])
                ->where('store_id = ?', $storeId)
        ) as $productId => $token) {
            $marks[(int)$productId] = (string)$token;
        }

        return $marks;
    }

    /**
     * Mark products to send again. A product marked again gets a new token, so a run that read the old
     * one does not remove the new mark.
     *
     * @param int $storeId
     * @param int[] $productIds
     * @return void
     */
    public function mark(int $storeId, array $productIds): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);

        $productIds = array_values(array_unique(array_map('intval', $productIds)));
        foreach (array_chunk($productIds, self::BATCH_SIZE) as $batch) {
            $rows = [];
            foreach ($batch as $productId) {
                $rows[] = ['store_id' => $storeId, 'product_id' => $productId, 'token' => bin2hex(random_bytes(16))];
            }
            $connection->insertOnDuplicate($table, $rows, ['token']);
        }
    }

    /**
     * Remove the marks a run read, once their products were sent
     *
     * @param int $storeId
     * @param string[] $tokens
     * @return void
     */
    public function clear(int $storeId, array $tokens): void
    {
        $connection = $this->resource->getConnection();
        $table = $this->resource->getTableName(self::TABLE);

        foreach (array_chunk(array_values($tokens), self::BATCH_SIZE) as $batch) {
            $connection->delete($table, ['store_id = ?' => $storeId, 'token IN (?)' => $batch]);
        }
    }
}
