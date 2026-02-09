<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\ResourceModel;

use Magento\Framework\Model\ResourceModel\Db\AbstractDb;

/**
 * BradSearch Sync State Resource Model
 */
class SyncState extends AbstractDb
{
    /**
     * @var string
     */
    protected $_idFieldName = 'id';

    /**
     * Initialize resource model
     *
     * @return void
     */
    protected function _construct(): void
    {
        $this->_init('bradsearch_sync_state', 'id');
    }

    /**
     * Load state by store ID
     *
     * @param \BradSearch\SearchGraphQl\Model\SyncState $object
     * @param int $storeId
     * @return $this
     */
    public function loadByStoreId(\BradSearch\SearchGraphQl\Model\SyncState $object, int $storeId): self
    {
        $connection = $this->getConnection();
        $select = $connection->select()
            ->from($this->getMainTable())
            ->where('store_id = ?', $storeId);

        $data = $connection->fetchRow($select);

        if ($data) {
            $object->setData($data);
        }

        return $this;
    }
}
