<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\Test\Integration;

/**
 * The bradsearch_sync_changed_products cron job reads Magento's search changelog and sends the changed
 * products to the BradSearch webhook.
 */
class SyncCronTest extends IntegrationTestCase
{
    private const WEBHOOK_PATH = '/webhook/products';

    protected function setUp(): void
    {
        $this->resetBradSearch();
        $this->stubBradSearch([
            'request' => ['method' => 'POST', 'urlPath' => self::WEBHOOK_PATH],
            'response' => ['status' => 200, 'jsonBody' => ['status' => 'ok']],
        ]);

        // A store's first run starts its cursor at the changelog's end, so make sure that run is behind us
        // and that whatever earlier tests left is sent, then start with an empty journal.
        $this->renameProduct('BRAD-IT-ALPHA');
        $this->runSync();
        $this->clearBradSearchJournal();
    }

    public function testSendsAChangedProductToTheWebhook(): void
    {
        $this->renameProduct('BRAD-IT-BETA');

        $this->runSync();

        $requests = $this->webhookRequests();
        $this->assertCount(1, $requests);
        $request = $requests[0];
        $this->assertSame(['product_ids' => [$this->productId('BRAD-IT-BETA')]], json_decode($request['body'], true));
        $this->assertSame('Bearer ' . self::WEBHOOK_TOKEN, $request['headers']['Authorization']);
        $this->assertSame('1', $request['headers']['X-BradSearch-Changes-Only']);

        // Sent once: with no new change the next run sends nothing.
        $this->runSync();
        $this->assertCount(1, $this->webhookRequests());
    }

    /**
     * Change the product's name in the database. Magento's triggers log the row change in the changelog, as
     * they do for a product save. The name keeps its words, so the product stays findable by them.
     */
    private function renameProduct(string $sku): void
    {
        $statement = $this->db()->prepare(
            "UPDATE catalog_product_entity_varchar v
             JOIN eav_attribute a ON a.attribute_id = v.attribute_id AND a.attribute_code = 'name'
                 AND a.entity_type_id = 4
             SET v.value = CONCAT(SUBSTRING_INDEX(v.value, ' #', 1), ?)
             WHERE v.entity_id = ? AND v.store_id = 0"
        );
        $statement->execute([' #' . uniqid(), $this->productId($sku)]);
        $this->assertSame(1, $statement->rowCount(), "Name of $sku not changed");
    }

    /**
     * Magento's index job first (the sync holds a product until Magento has indexed it), then the sync
     */
    private function runSync(): void
    {
        $this->runCronJob('index', 'indexer_update_all_views');
        $this->runCronJob('default', 'bradsearch_sync_changed_products');
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function webhookRequests(): array
    {
        return $this->bradSearchRequests(['method' => 'POST', 'urlPath' => self::WEBHOOK_PATH]);
    }
}
