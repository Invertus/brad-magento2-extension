<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\ResourceModel\SyncState;

use Magento\Framework\Model\ResourceModel\Db\Collection\AbstractCollection;
use BradSearch\SearchGraphQl\Model\SyncState;
use BradSearch\SearchGraphQl\Model\ResourceModel\SyncState as SyncStateResource;

/**
 * BradSearch Sync State Collection
 */
class Collection extends AbstractCollection
{
    /**
     * @var string
     */
    protected $_idFieldName = 'id';

    /**
     * Initialize collection
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init(SyncState::class, SyncStateResource::class);
    }
}
