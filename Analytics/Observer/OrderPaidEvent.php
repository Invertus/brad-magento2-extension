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
 * Observer for sales_order_payment_capture event
 *
 * Sends order-paid event to analytics API when payment is captured.
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
        // Extract payment from event
        $payment = $observer->getEvent()->getData('payment');

        if (!$payment) {
            return;
        }

        /** @var \Magento\Sales\Model\Order\Payment $payment */

        // Get order from payment
        $order = $payment->getOrder();

        // Validate order exists
        if (!$order || !$order->getId()) {
            return;
        }

        /** @var \Magento\Sales\Model\Order $order */

        // Get store ID
        $storeId = (int) $order->getStoreId();

        // Send event (wrapped in try-catch to prevent checkout disruption)
        try {
            $this->eventNotifier->sendOrderPaidEvent($order, $storeId);
        } catch (\Exception $e) {
            // Log error but don't throw - must not break checkout flow
            $this->logger->error('BradSearch Analytics: Failed to send order-paid event', [
                'order_id' => $order->getId(),
                'store_id' => $storeId,
                'error' => $e->getMessage()
            ]);
        }
    }
}
