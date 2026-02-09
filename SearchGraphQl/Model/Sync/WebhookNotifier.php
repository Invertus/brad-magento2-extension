<?php
/**
 * Copyright (c) BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\Sync;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\HTTP\Client\Curl;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Webhook Notifier Service
 *
 * Sends HTTP POST notifications to Laravel backend when products change.
 */
class WebhookNotifier
{
    private const CONFIG_PATH_WEBHOOK_URL = 'bradsearch_search/sync/webhook_url';
    private const CONFIG_PATH_SECURE_TOKEN = 'bradsearch_search/sync/secure_token';
    private const CONFIG_PATH_SYNC_ENABLED = 'bradsearch_search/sync/enabled';

    private const TIMEOUT = 30;
    private const CONNECT_TIMEOUT = 10;

    /**
     * @var Curl
     */
    private Curl $curl;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var EncryptorInterface
     */
    private EncryptorInterface $encryptor;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param Curl $curl
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     * @param LoggerInterface $logger
     */
    public function __construct(
        Curl $curl,
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor,
        LoggerInterface $logger
    ) {
        $this->curl = $curl;
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
        $this->logger = $logger;
    }

    /**
     * Check if sync is enabled for the given store
     *
     * @param int $storeId
     * @return bool
     */
    public function isSyncEnabled(int $storeId): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::CONFIG_PATH_SYNC_ENABLED,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Send webhook notification with changed product IDs
     *
     * @param array $productIds Array of product IDs that changed
     * @param int $storeId Store ID
     * @param int $versionId Current changelog version ID
     * @return bool True on success, false on failure
     */
    public function notify(array $productIds, int $storeId, int $versionId): bool
    {
        if (empty($productIds)) {
            $this->logger->debug('BradSearch: No products to notify');
            return true;
        }

        $webhookUrl = $this->getWebhookUrl($storeId);
        $secureToken = $this->getSecureToken($storeId);

        if (empty($webhookUrl)) {
            $this->logger->warning('BradSearch: Webhook URL not configured', [
                'store_id' => $storeId
            ]);
            return false;
        }

        $payload = [
            'product_ids' => $productIds,
        ];

        try {
            $this->setupCurl($secureToken);
            $this->curl->post($webhookUrl, json_encode($payload));

            $statusCode = $this->curl->getStatus();
            $responseBody = $this->curl->getBody();

            if ($statusCode >= 200 && $statusCode < 300) {
                $this->logger->info('BradSearch: Webhook notification sent successfully', [
                    'store_id' => $storeId,
                    'product_count' => count($productIds),
                    'version_id' => $versionId,
                    'status_code' => $statusCode
                ]);
                return true;
            }

            $this->logger->error('BradSearch: Webhook notification failed', [
                'store_id' => $storeId,
                'product_count' => count($productIds),
                'status_code' => $statusCode,
                'response' => substr($responseBody, 0, 500)
            ]);
            return false;
        } catch (\Exception $e) {
            $this->logger->error('BradSearch: Webhook notification exception', [
                'store_id' => $storeId,
                'product_count' => count($productIds),
                'error' => $e->getMessage()
            ]);
            return false;
        }
    }

    /**
     * Setup curl client with headers and options
     *
     * @param string|null $secureToken
     * @return void
     */
    private function setupCurl(?string $secureToken): void
    {
        $this->curl->addHeader('Content-Type', 'application/json');
        $this->curl->addHeader('Accept', 'application/json');

        if (!empty($secureToken)) {
            $this->curl->addHeader('Authorization', 'Bearer ' . $secureToken);
        }

        $this->curl->setOption(CURLOPT_TIMEOUT, self::TIMEOUT);
        $this->curl->setOption(CURLOPT_CONNECTTIMEOUT, self::CONNECT_TIMEOUT);
        $this->curl->setOption(CURLOPT_RETURNTRANSFER, true);
    }

    /**
     * Get webhook URL from configuration
     *
     * @param int $storeId
     * @return string|null
     */
    private function getWebhookUrl(int $storeId): ?string
    {
        return $this->scopeConfig->getValue(
            self::CONFIG_PATH_WEBHOOK_URL,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );
    }

    /**
     * Get secure token from configuration
     *
     * @param int $storeId
     * @return string|null
     */
    private function getSecureToken(int $storeId): ?string
    {
        $encryptedToken = $this->scopeConfig->getValue(
            self::CONFIG_PATH_SECURE_TOKEN,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if ($encryptedToken === null) {
            return null;
        }

        return $this->encryptor->decrypt($encryptedToken);
    }
}
