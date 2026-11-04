<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\Analytics\Model\OrderEvents;

use Magento\Framework\App\ResourceConnection;
use Magento\Framework\DB\Adapter\AdapterInterface;

/**
 * Paid orders waiting to be sent. Times are UTC strings (Y-m-d H:i:s).
 */
class Outbox
{
    public const TABLE = 'bradsearch_order_outbox';

    public const STATUS_PENDING = 'pending';
    public const STATUS_SENT = 'sent';
    public const STATUS_FAILED = 'failed';

    private const ERROR_LENGTH = 255;

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
     * Queues an order once; answers false when it was queued before.
     */
    public function add(int $orderId, int $storeId, string $body, string $now): bool
    {
        return $this->connection()->insertArray(
            $this->table(),
            ['order_id', 'store_id', 'body', 'status', 'attempts', 'next_attempt_at', 'created_at', 'updated_at'],
            [[$orderId, $storeId, $body, self::STATUS_PENDING, 0, $now, $now, $now]],
            AdapterInterface::INSERT_IGNORE
        ) === 1;
    }

    /**
     * Leases the oldest due row until $leaseUntil, so a parallel run skips it.
     *
     * @return array{entity_id: int, order_id: int, store_id: int, body: string, attempts: int, lease: string}|null
     */
    public function claimNext(string $now, string $leaseUntil): ?array
    {
        $connection = $this->connection();
        $row = $connection->fetchRow(
            $connection->select()
                ->from($this->table(), ['entity_id', 'order_id', 'store_id', 'body', 'attempts'])
                ->where('status = ?', self::STATUS_PENDING)
                ->where('next_attempt_at <= ?', $now)
                ->order(['next_attempt_at ASC', 'entity_id ASC'])
                ->limit(1)
        );

        if (!$row) {
            return null;
        }

        $leased = $connection->update(
            $this->table(),
            ['next_attempt_at' => $leaseUntil],
            [
                'entity_id = ?' => (int) $row['entity_id'],
                'status = ?' => self::STATUS_PENDING,
                'next_attempt_at <= ?' => $now,
            ]
        );

        if ($leased !== 1) {
            return null;
        }

        return [
            'entity_id' => (int) $row['entity_id'],
            'order_id' => (int) $row['order_id'],
            'store_id' => (int) $row['store_id'],
            'body' => (string) $row['body'],
            'attempts' => (int) $row['attempts'],
            'lease' => $leaseUntil,
        ];
    }

    // Result writes apply only while the row still holds $lease.
    public function markSent(int $id, string $lease, int $attempts, string $now): void
    {
        $this->finish($id, $lease, [
            'status' => self::STATUS_SENT,
            'attempts' => $attempts,
            'last_error' => null,
            'updated_at' => $now,
        ]);
    }

    public function retryLater(int $id, string $lease, int $attempts, string $nextAttemptAt, string $error, string $now): void
    {
        $this->finish($id, $lease, [
            'attempts' => $attempts,
            'next_attempt_at' => $nextAttemptAt,
            'last_error' => substr($error, 0, self::ERROR_LENGTH),
            'updated_at' => $now,
        ]);
    }

    public function markFailed(int $id, string $lease, int $attempts, string $error, string $now): void
    {
        $this->finish($id, $lease, [
            'status' => self::STATUS_FAILED,
            'attempts' => $attempts,
            'last_error' => substr($error, 0, self::ERROR_LENGTH),
            'updated_at' => $now,
        ]);
    }

    public function purge(string $sentBefore, string $failedBefore): void
    {
        $connection = $this->connection();
        $connection->delete($this->table(), [
            '(status = ' . $connection->quote(self::STATUS_SENT) . ' AND updated_at < ' . $connection->quote($sentBefore) . ')'
            . ' OR (status = ' . $connection->quote(self::STATUS_FAILED) . ' AND updated_at < ' . $connection->quote($failedBefore) . ')',
        ]);
    }

    /**
     * @param array<string, mixed> $values
     */
    private function finish(int $id, string $lease, array $values): void
    {
        $this->connection()->update($this->table(), $values, [
            'entity_id = ?' => $id,
            'status = ?' => self::STATUS_PENDING,
            'next_attempt_at = ?' => $lease,
        ]);
    }

    private function connection(): AdapterInterface
    {
        return $this->resource->getConnection();
    }

    private function table(): string
    {
        return $this->resource->getTableName(self::TABLE);
    }
}
