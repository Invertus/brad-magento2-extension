<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\Analytics\Model;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Quote\Model\QuoteIdToMaskedQuoteIdInterface;
use Magento\Sales\Model\Order;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Event Notifier Service
 *
 * Sends order-paid events to analytics API when payment is captured.
 */
class EventNotifier
{
    private const CONFIG_PATH_ENABLED = 'bradsearch_analytics/general/enabled';
    private const CONFIG_PATH_API_URL = 'bradsearch_analytics/general/api_url';
    private const CONFIG_PATH_WEBSITE_ID = 'bradsearch_analytics/general/website_id';

    private const TIMEOUT = 10;
    private const CONNECT_TIMEOUT = 5;

    /**
     * @var Curl
     */
    private Curl $curl;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var QuoteIdToMaskedQuoteIdInterface
     */
    private QuoteIdToMaskedQuoteIdInterface $quoteIdToMaskedQuoteId;

    /**
     * @param Curl $curl
     * @param ScopeConfigInterface $scopeConfig
     * @param LoggerInterface $logger
     * @param QuoteIdToMaskedQuoteIdInterface $quoteIdToMaskedQuoteId
     */
    public function __construct(
        Curl $curl,
        ScopeConfigInterface $scopeConfig,
        LoggerInterface $logger,
        QuoteIdToMaskedQuoteIdInterface $quoteIdToMaskedQuoteId
    ) {
        $this->curl = $curl;
        $this->scopeConfig = $scopeConfig;
        $this->logger = $logger;
        $this->quoteIdToMaskedQuoteId = $quoteIdToMaskedQuoteId;
    }

    /**
     * Send order-paid event to analytics API
     *
     * @param Order $order
     * @param int $storeId
     * @return bool True on success, false on failure
     */
    public function sendOrderPaidEvent(Order $order, int $storeId): bool
    {
        // Check if analytics is enabled
        if (!$this->isEnabled($storeId)) {
            return false;
        }

        $apiUrl = $this->getApiUrl($storeId);
        $websiteId = $this->getWebsiteId($storeId);

        if (empty($apiUrl)) {
            $this->logger->warning('BradSearch Analytics: API URL not configured', [
                'store_id' => $storeId,
                'order_id' => $order->getId()
            ]);
            return false;
        }

        // Build event data
        $eventData = $this->buildEventData($order);

        // Build payload matching PrestaShop structure
        $payload = [
            'type' => 'event',
            'payload' => [
                'website' => $websiteId,
                'name' => 'order-paid',
                'data' => $eventData
            ]
        ];

        try {
            $this->setupCurl();

            // Append /api/send if not already present
            $endpoint = $this->buildEndpointUrl($apiUrl);

            $this->curl->post($endpoint, json_encode($payload));

            $statusCode = $this->curl->getStatus();
            $responseBody = $this->curl->getBody();

            if ($statusCode === 200) {
                $this->logger->debug('BradSearch Analytics: Order-paid event sent successfully', [
                    'order_id' => $order->getId(),
                    'store_id' => $storeId,
                    'product_count' => count($order->getAllVisibleItems())
                ]);
                return true;
            }

            $this->logger->error('BradSearch Analytics: Order-paid event failed - non-200 status code', [
                'order_id' => $order->getId(),
                'store_id' => $storeId,
                'status_code' => $statusCode,
                'response' => substr($responseBody, 0, 500)
            ]);
            return false;
        } catch (\Throwable $e) {
            $this->logger->error('BradSearch Analytics: Order-paid event exception', [
                'order_id' => $order->getId(),
                'store_id' => $storeId,
                'error' => $e->getMessage(),
                'error_class' => get_class($e)
            ]);
            return false;
        }
    }

    /**
     * Build event data from order
     *
     * @param Order $order
     * @return array
     */
    private function buildEventData(Order $order): array
    {
        // Get masked cart ID (string) to match frontend cart ID
        $cartId = $this->getMaskedCartId($order);

        // Build base data
        $eventData = [
            'order_id' => (int) $order->getId(),
            'cart_id' => $cartId,
            'customer_id' => $order->getCustomerId() ? (int) $order->getCustomerId() : null,
            'total_paid_tax_incl' => (float) $order->getGrandTotal(),
        ];

        // Add product totals (matching PrestaShop format: product_{id}_total)
        foreach ($order->getAllVisibleItems() as $item) {
            /** @var \Magento\Sales\Model\Order\Item $item */
            $productId = (int) $item->getProductId();
            $rowTotal = (float) $item->getRowTotalInclTax();

            $eventData['product_' . $productId . '_total'] = $rowTotal;
        }

        return $eventData;
    }

    /**
     * Get masked cart ID from quote ID
     *
     * Returns the masked cart ID (string) that matches what the frontend uses.
     * Falls back to quote ID as string if masked ID doesn't exist.
     *
     * @param Order $order
     * @return string
     */
    private function getMaskedCartId(Order $order): string
    {
        $quoteId = $order->getQuoteId();

        try {
            // Try to get masked ID for guest carts
            $maskedId = $this->quoteIdToMaskedQuoteId->execute((int) $quoteId);

            if (!empty($maskedId)) {
                return $maskedId;
            }
        } catch (\Exception $e) {
            // No masked ID exists (common for customer carts)
            $this->logger->debug('BradSearch Analytics: No masked cart ID found, using quote ID', [
                'order_id' => $order->getId(),
                'quote_id' => $quoteId,
                'error' => $e->getMessage()
            ]);
        }

        // Fallback to quote ID as string for customer carts
        return (string) $quoteId;
    }

    /**
     * Build endpoint URL
     *
     * @param string $apiUrl
     * @return string
     */
    private function buildEndpointUrl(string $apiUrl): string
    {
        // Remove trailing slash
        $apiUrl = rtrim($apiUrl, '/');

        // Check if /api/send already present
        if (strpos($apiUrl, '/api/send') !== false) {
            return $apiUrl;
        }

        return $apiUrl . '/api/send';
    }

    /**
     * Setup curl client with headers and options
     *
     * Matches PrestaShop implementation with User-Agent to avoid bot detection
     *
     * @return void
     */
    private function setupCurl(): void
    {
        $this->curl->addHeader('Content-Type', 'application/json');
        // User-Agent to avoid bot detection (matching PrestaShop)
        $this->curl->addHeader(
            'User-Agent',
            'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2228.0 Safari/537.36'
        );

        $this->curl->setOption(CURLOPT_TIMEOUT, self::TIMEOUT);
        $this->curl->setOption(CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        $this->curl->setOption(CURLOPT_RETURNTRANSFER, true);
    }

    /**
     * Check if analytics is enabled for the given store
     *
     * @param int $storeId
     * @return bool
     */
    private function isEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::CONFIG_PATH_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get API URL from configuration
     *
     * @param int $storeId
     * @return string|null
     */
    private function getApiUrl(int $storeId): ?string
    {
        return $this->scopeConfig->getValue(
            self::CONFIG_PATH_API_URL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get website ID from configuration
     *
     * @param int $storeId
     * @return string|null
     */
    private function getWebsiteId(int $storeId): ?string
    {
        return $this->scopeConfig->getValue(
            self::CONFIG_PATH_WEBSITE_ID,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }
}
