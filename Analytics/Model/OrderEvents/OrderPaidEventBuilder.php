<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\Analytics\Model\OrderEvents;

use Magento\Quote\Model\QuoteIdToMaskedQuoteIdInterface;
use Magento\Sales\Model\Order;
use Psr\Log\LoggerInterface;

class OrderPaidEventBuilder
{
    /**
     * @var QuoteIdToMaskedQuoteIdInterface
     */
    private QuoteIdToMaskedQuoteIdInterface $quoteIdToMaskedQuoteId;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param QuoteIdToMaskedQuoteIdInterface $quoteIdToMaskedQuoteId
     * @param LoggerInterface $logger
     */
    public function __construct(QuoteIdToMaskedQuoteIdInterface $quoteIdToMaskedQuoteId, LoggerInterface $logger)
    {
        $this->quoteIdToMaskedQuoteId = $quoteIdToMaskedQuoteId;
        $this->logger = $logger;
    }

    /**
     * @return array<string, mixed>
     */
    public function build(Order $order, string $websiteId, int $paidAt): array
    {
        $data = [
            'order_id' => (int) $order->getId(),
            'cart_id' => $this->getMaskedCartId($order),
            'customer_id' => $order->getCustomerId() ? (int) $order->getCustomerId() : null,
            'total_paid_tax_incl' => (float) $order->getGrandTotal(),
        ];

        foreach ($order->getAllVisibleItems() as $item) {
            $data['product_' . (int) $item->getProductId() . '_total'] = (float) $item->getRowTotalInclTax();
        }

        return [
            'type' => 'event',
            'payload' => [
                'website' => $websiteId,
                'name' => 'order-paid',
                // A late send keeps the paid time, so the order lands on the right day.
                'timestamp' => $paidAt,
                'data' => $data,
            ],
        ];
    }

    // The masked id is what the storefront sends as cart_id; customer carts have none.
    private function getMaskedCartId(Order $order): string
    {
        $quoteId = (int) $order->getQuoteId();

        try {
            $maskedId = $this->quoteIdToMaskedQuoteId->execute($quoteId);

            if (!empty($maskedId)) {
                return $maskedId;
            }
        } catch (\Exception $e) {
            $this->logger->debug('BradSearch Analytics: No masked cart ID found, using quote ID', [
                'order_id' => $order->getId(),
                'quote_id' => $quoteId,
            ]);
        }

        return (string) $quoteId;
    }
}
