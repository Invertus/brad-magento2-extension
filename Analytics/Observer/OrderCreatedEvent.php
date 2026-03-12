<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\Analytics\Observer;

use BradSearch\Analytics\Model\EventNotifier;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

/**
 * Observer for checkout_submit_all_after event
 *
 * Sends order-paid event to analytics API when a new order is placed.
 */
class OrderCreatedEvent implements ObserverInterface
{
    /**
     * @var EventNotifier
     */
    private EventNotifier $eventNotifier;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param EventNotifier $eventNotifier
     * @param LoggerInterface $logger
     */
    public function __construct(
        EventNotifier $eventNotifier,
        LoggerInterface $logger
    ) {
        $this->eventNotifier = $eventNotifier;
        $this->logger = $logger;
    }

    /**
     * Execute observer
     *
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

        try {
            $this->eventNotifier->sendOrderPaidEvent($order, $storeId);
        } catch (\Exception $e) {
            $this->logger->error('BradSearch Analytics: Failed to send order-paid event', [
                'order_id' => $order->getId(),
                'store_id' => $storeId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
