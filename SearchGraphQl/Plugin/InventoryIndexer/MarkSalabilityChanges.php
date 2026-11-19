<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Plugin\InventoryIndexer;

use BradSearch\SearchGraphQl\Model\Sync\ResendProducts;
use BradSearch\SearchGraphQl\Model\Sync\WebhookNotifier;
use Magento\Framework\App\ResourceConnection;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

/**
 * Sends the products an order made sellable or not sellable.
 *
 * With Magento's inventory modules an order only adds a reservation. A queue consumer then writes the new
 * stock status straight into the stock index (or a stock's own index), with no changelog row, so the
 * sync would never see it. Plugged after Magento\InventoryIndexer\Model\Queue\UpdateIndexSalabilityStatus,
 * which returns only the skus whose status really changed, plus their parents.
 */
class MarkSalabilityChanges
{
    private const BATCH_SIZE = 1000;

    /**
     * @var ResourceConnection
     */
    private ResourceConnection $resource;

    /**
     * @var ResendProducts
     */
    private ResendProducts $resendProducts;

    /**
     * @var WebhookNotifier
     */
    private WebhookNotifier $webhookNotifier;

    /**
     * @var StoreManagerInterface
     */
    private StoreManagerInterface $storeManager;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param ResourceConnection $resource
     * @param ResendProducts $resendProducts
     * @param WebhookNotifier $webhookNotifier
     * @param StoreManagerInterface $storeManager
     * @param LoggerInterface $logger
     */
    public function __construct(
        ResourceConnection $resource,
        ResendProducts $resendProducts,
        WebhookNotifier $webhookNotifier,
        StoreManagerInterface $storeManager,
        LoggerInterface $logger
    ) {
        $this->resource = $resource;
        $this->resendProducts = $resendProducts;
        $this->webhookNotifier = $webhookNotifier;
        $this->storeManager = $storeManager;
        $this->logger = $logger;
    }

    /**
     * Untyped subject: the inventory modules may be missing.
     *
     * @param mixed $subject
     * @param mixed $result Sku => is sellable now
     * @return mixed
     */
    public function afterExecute($subject, $result)
    {
        if (!is_array($result) || empty($result)) {
            return $result;
        }

        try {
            if (!$this->resendProducts->isAvailable()) {
                return $result;
            }

            $websitesByProduct = $this->websitesByProduct(array_map('strval', array_keys($result)));
            $marked = 0;
            foreach ($this->storeManager->getStores() as $store) {
                $storeId = (int)$store->getId();
                if (!$this->webhookNotifier->isSyncEnabled($storeId)) {
                    continue;
                }
                $websiteId = (int)$store->getWebsiteId();
                $productIds = array_keys(array_filter(
                    $websitesByProduct,
                    static function (array $websiteIds) use ($websiteId): bool {
                        return isset($websiteIds[$websiteId]);
                    }
                ));
                if (!empty($productIds)) {
                    $this->resendProducts->mark($storeId, $productIds);
                    $marked += count($productIds);
                }
            }

            if ($marked > 0) {
                $this->logger->info('BradSearch Sync: An order changed products\' stock status, sending them', [
                    'product_count' => count($websitesByProduct),
                    'marks' => $marked
                ]);
            }
        } catch (\Throwable $e) {
            // The order's stock update itself has already happened; only the push is lost until the next change.
            $this->logger->error('BradSearch Sync: Could not mark products whose stock status an order changed', [
                'error' => $e->getMessage()
            ]);
        }

        return $result;
    }

    /**
     * @param string[] $skus
     * @return array<int, array<int, true>> product id => website ids it is assigned to
     */
    private function websitesByProduct(array $skus): array
    {
        $connection = $this->resource->getConnection();
        $products = $this->resource->getTableName('catalog_product_entity');
        $websites = $this->resource->getTableName('catalog_product_website');

        $result = [];
        foreach (array_chunk(array_values(array_unique($skus)), self::BATCH_SIZE) as $batch) {
            foreach ($connection->fetchAll(
                $connection->select()
                    ->from(['p' => $products], ['entity_id'])
                    ->joinInner(['w' => $websites], 'w.product_id = p.entity_id', ['website_id'])
                    ->where('p.sku IN (?)', $batch)
            ) as $row) {
                $result[(int)$row['entity_id']][(int)$row['website_id']] = true;
            }
        }

        return $result;
    }
}
