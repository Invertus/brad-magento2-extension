<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model;

use Magento\Framework\Model\AbstractModel;
use BradSearch\SearchGraphQl\Model\ResourceModel\SyncState as SyncStateResource;

/**
 * BradSearch Sync State Model
 *
 * Tracks the last processed changelog version ID per store for incremental sync.
 */
class SyncState extends AbstractModel
{
    /**
     * @var string
     */
    protected $_eventPrefix = 'bradsearch_sync_state';

    /**
     * Initialize resource model
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(SyncStateResource::class);
    }

    /**
     * Get store ID
     *
     * @return int
     */
    public function getStoreId(): int
    {
        return (int)$this->getData('store_id');
    }

    /**
     * Set store ID
     *
     * @param int $storeId
     * @return $this
     */
    public function setStoreId(int $storeId): self
    {
        return $this->setData('store_id', $storeId);
    }

    /**
     * Get last processed version ID
     *
     * @return int
     */
    public function getLastVersionId(): int
    {
        return (int)$this->getData('last_version_id');
    }

    /**
     * Set last processed version ID
     *
     * @param int $versionId
     * @return $this
     */
    public function setLastVersionId(int $versionId): self
    {
        return $this->setData('last_version_id', $versionId);
    }

    /**
     * Get last sync timestamp
     *
     * @return string|null
     */
    public function getLastSyncAt(): ?string
    {
        return $this->getData('last_sync_at');
    }

    /**
     * Set last sync timestamp
     *
     * @param string $timestamp
     * @return $this
     */
    public function setLastSyncAt(string $timestamp): self
    {
        return $this->setData('last_sync_at', $timestamp);
    }
}
