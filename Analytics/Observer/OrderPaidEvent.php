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
 * Observer for sales_order_save_after event
 *
 * Sends order-paid event to analytics API when payment_validated transitions from 0 to 1.
 *
 * @todo {BRD-748} - this is custom payment event validation logic check not magento2 default. This must therefore
 * be configurable in the future
 *
 */
class OrderPaidEvent implements ObserverInterface
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

        // Only fire when payment_validated transitions from 0 to 1
        $currentValue = (int) $order->getData('payment_validated');
        $originalValue = (int) $order->getOrigData('payment_validated');

        if ($currentValue !== 1 || $originalValue === 1) {
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
