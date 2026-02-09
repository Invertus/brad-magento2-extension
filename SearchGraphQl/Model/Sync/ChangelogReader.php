<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\Sync;

use Magento\Framework\App\ResourceConnection;
use Psr\Log\LoggerInterface;

/**
 * Changelog Reader Service
 *
 * Reads changed product IDs from the catalogsearch_fulltext_cl changelog table.
 * This table is populated by database triggers when product data changes.
 */
class ChangelogReader
{
    private const CHANGELOG_TABLE = 'catalogsearch_fulltext_cl';
    private const ENTITY_COLUMN = 'entity_id';
    private const VERSION_COLUMN = 'version_id';

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resourceConnection;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param ResourceConnection $resourceConnection
     * @param LoggerInterface $logger
     */
    public function __construct(
        ResourceConnection $resourceConnection,
        LoggerInterface $logger
    ) {
        $this->resourceConnection = $resourceConnection;
        $this->logger = $logger;
    }

    /**
     * Get changed product IDs from changelog since last version
     *
     * @param int $fromVersionId Last processed version ID (exclusive)
     * @param int|null $toVersionId Optional upper bound (inclusive), defaults to current max
     * @return array Array of unique product IDs
     */
    public function getChangedProductIds(int $fromVersionId, ?int $toVersionId = null): array
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName(self::CHANGELOG_TABLE);

            // Check if table exists
            if (!$connection->isTableExists($tableName)) {
                $this->logger->warning('BradSearch: Changelog table does not exist', [
                    'table' => $tableName
                ]);
                return [];
            }

            $toVersionId = $toVersionId ?? $this->getCurrentVersion();

            // No new changes
            if ($toVersionId <= $fromVersionId) {
                return [];
            }

            $select = $connection->select()
                ->distinct(true)
                ->from($tableName, [self::ENTITY_COLUMN])
                ->where(self::VERSION_COLUMN . ' > ?', $fromVersionId)
                ->where(self::VERSION_COLUMN . ' <= ?', $toVersionId);

            $productIds = $connection->fetchCol($select);

            $this->logger->debug('BradSearch: Read changelog entries', [
                'from_version' => $fromVersionId,
                'to_version' => $toVersionId,
                'product_count' => count($productIds)
            ]);

            return array_map('intval', $productIds);
        } catch (\Exception $e) {
            $this->logger->error('BradSearch: Error reading changelog', [
                'error' => $e->getMessage(),
                'from_version' => $fromVersionId
            ]);
            throw $e;
        }
    }

    /**
     * Get current max version ID from changelog
     *
     * @return int
     */
    public function getCurrentVersion(): int
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName(self::CHANGELOG_TABLE);

            if (!$connection->isTableExists($tableName)) {
                return 0;
            }

            $select = $connection->select()
                ->from($tableName, ['MAX(' . self::VERSION_COLUMN . ')']);

            $version = $connection->fetchOne($select);

            return (int)($version ?: 0);
        } catch (\Exception $e) {
            $this->logger->error('BradSearch: Error getting current version', [
                'error' => $e->getMessage()
            ]);
            return 0;
        }
    }

    /**
     * Check if changelog table exists and has data
     *
     * @return bool
     */
    public function isChangelogAvailable(): bool
    {
        try {
            $connection = $this->resourceConnection->getConnection();
            $tableName = $this->resourceConnection->getTableName(self::CHANGELOG_TABLE);

            return $connection->isTableExists($tableName);
        } catch (\Exception $e) {
            $this->logger->error('BradSearch: Error checking changelog availability', [
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }
}
