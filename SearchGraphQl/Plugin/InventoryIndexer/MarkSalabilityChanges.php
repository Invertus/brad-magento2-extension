<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Plugin\InventoryIndexer;

use BradSearch\SearchGraphQl\Model\Sync\ProductRelations;
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
 * sync would never see it. Plugged after the two steps of that consumer that write the status
 * (Magento\InventoryIndexer\Model\Queue\UpdateIndexSalabilityStatus\UpdateLegacyStock for the default
 * stock, ...\IndexProcessor for the others). Each returns only the skus whose status really changed,
 * after writing it. The consumer's own result is not used: it adds the parents with array_merge, which
 * renumbers numeric sku keys, so the parents are added here from catalog_product_relation.
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
     * @var ProductRelations
     */
    private ProductRelations $productRelations;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param ResourceConnection $resource
     * @param ResendProducts $resendProducts
     * @param WebhookNotifier $webhookNotifier
     * @param StoreManagerInterface $storeManager
     * @param ProductRelations $productRelations
     * @param LoggerInterface $logger
     */
    public function __construct(
        ResourceConnection $resource,
        ResendProducts $resendProducts,
        WebhookNotifier $webhookNotifier,
        StoreManagerInterface $storeManager,
        ProductRelations $productRelations,
        LoggerInterface $logger
    ) {
        $this->resource = $resource;
        $this->resendProducts = $resendProducts;
        $this->webhookNotifier = $webhookNotifier;
        $this->storeManager = $storeManager;
        $this->productRelations = $productRelations;
        $this->logger = $logger;
    }

    /**
     * Untyped subject: the inventory modules may be missing.
     *
     * @param mixed $subject
     * @param mixed $result Sku => is sellable now; a numeric sku is an int key
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

            $productIds = $this->productIds(array_map('strval', array_keys($result)));
            if (empty($productIds)) {
                return $result;
            }
            foreach ($this->productRelations->parentsOf($productIds) as $parentIds) {
                $productIds = array_merge($productIds, $parentIds);
            }
            $websitesByProduct = $this->websitesByProduct(array_values(array_unique($productIds)));

            $marked = 0;
            foreach ($this->storeManager->getStores() as $store) {
                $storeId = (int)$store->getId();
                if (!$this->webhookNotifier->isSyncEnabled($storeId)) {
                    continue;
                }
                $websiteId = (int)$store->getWebsiteId();
                $storeProductIds = array_keys(array_filter(
                    $websitesByProduct,
                    static function (array $websiteIds) use ($websiteId): bool {
                        return isset($websiteIds[$websiteId]);
                    }
                ));
                if (!empty($storeProductIds)) {
                    $this->resendProducts->mark($storeId, $storeProductIds);
                    $marked += count($storeProductIds);
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
     * @return int[]
     */
    private function productIds(array $skus): array
    {
        $connection = $this->resource->getConnection();
        $products = $this->resource->getTableName('catalog_product_entity');

        $ids = [];
        foreach (array_chunk(array_values(array_unique($skus)), self::BATCH_SIZE) as $batch) {
            foreach ($connection->fetchCol(
                $connection->select()->from($products, ['entity_id'])->where('sku IN (?)', $batch)
            ) as $id) {
                $ids[] = (int)$id;
            }
        }

        return $ids;
    }

    /**
     * @param int[] $productIds
     * @return array<int, array<int, true>> product id => website ids it is assigned to
     */
    private function websitesByProduct(array $productIds): array
    {
        $connection = $this->resource->getConnection();
        $websites = $this->resource->getTableName('catalog_product_website');

        $result = [];
        foreach (array_chunk($productIds, self::BATCH_SIZE) as $batch) {
            foreach ($connection->fetchAll(
                $connection->select()
                    ->from($websites, ['product_id', 'website_id'])
                    ->where('product_id IN (?)', $batch)
            ) as $row) {
                $result[(int)$row['product_id']][(int)$row['website_id']] = true;
            }
        }

        return $result;
    }
}
