<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\Analytics\Observer;

use BradSearch\Analytics\Model\OrderEvents\OrderPaidEventBuilder;
use BradSearch\Analytics\Model\OrderEvents\Outbox;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

// Queues the order-paid event; the cron job sends it, so checkout never waits for Brad analytics.
class OrderCreatedEvent implements ObserverInterface
{
    private const CONFIG_PATH_ENABLED = 'bradsearch_analytics/general/enabled';
    private const CONFIG_PATH_API_URL = 'bradsearch_analytics/general/api_url';
    private const CONFIG_PATH_WEBSITE_ID = 'bradsearch_analytics/general/website_id';

    /**
     * @var OrderPaidEventBuilder
     */
    private OrderPaidEventBuilder $eventBuilder;

    /**
     * @var Outbox
     */
    private Outbox $outbox;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param OrderPaidEventBuilder $eventBuilder
     * @param Outbox $outbox
     * @param ScopeConfigInterface $scopeConfig
     * @param LoggerInterface $logger
     */
    public function __construct(
        OrderPaidEventBuilder $eventBuilder,
        Outbox $outbox,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger
    ) {
        $this->eventBuilder = $eventBuilder;
        $this->outbox = $outbox;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
    }

    /**
     * @param Observer $observer
     * @return void
     */
    public function execute(Observer $observer)
    {
        $order = $observer->getEvent()->getData('order');

        if (!$order || !$order->getId()) {
            return;
        }

        $storeId = (int) $order->getStoreId();

        if (!$this->scopeConfig->isSetFlag(self::CONFIG_PATH_ENABLED, ScopeInterface::SCOPE_STORE, $storeId)) {
            return;
        }

        $websiteId = (string) $this->config(self::CONFIG_PATH_WEBSITE_ID, $storeId);

        if (trim((string) $this->config(self::CONFIG_PATH_API_URL, $storeId)) === '' || $websiteId === '') {
            $this->logger->debug('BradSearch Analytics: API URL or website ID not configured, order not queued', [
                'store_id' => $storeId,
                'order_id' => $order->getId(),
            ]);

            return;
        }

        try {
            $paidAt = time();
            $body = json_encode($this->eventBuilder->build($order, $websiteId, $paidAt));

            if ($body === false) {
                $this->logger->warning('BradSearch Analytics: Order-paid event could not be encoded and is not sent', [
                    'order_id' => $order->getId(),
                ]);

                return;
            }

            $this->outbox->add((int) $order->getId(), $storeId, $body, gmdate('Y-m-d H:i:s', $paidAt));
        } catch (\Throwable $e) {
            $this->logger->error('BradSearch Analytics: Failed to queue order-paid event', [
                'order_id' => $order->getId(),
                'store_id' => $storeId,
                'error' => $e->getMessage(),
            ]);
        }
    }

    /**
     * @return mixed
     */
    private function config(string $path, int $storeId)
    {
        return $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE, $storeId);
    }
}
