<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\Api\Auth;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Validates API key from request headers for BradSearch private endpoint access
 *
 * When a valid API key is provided via X-BradSearch-Api-Key header,
 * stock filtering can be bypassed to allow sync operations to include
 * out-of-stock products.
 */
class ApiKeyValidator
{
    private const CONFIG_PATH_PRIVATE_API_KEY = 'bradsearch_search/private_endpoint/api_key';
    private const CONFIG_PATH_ENABLED = 'bradsearch_search/private_endpoint/enabled';
    private const HEADER_NAME = 'X-BradSearch-Api-Key';

    /**
     * @var RequestInterface
     */
    private RequestInterface $request;

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
     * @param RequestInterface $request
     * @param ScopeConfigInterface $scopeConfig
     * @param EncryptorInterface $encryptor
     * @param LoggerInterface $logger
     */
    public function __construct(
        RequestInterface $request,
        ScopeConfigInterface $scopeConfig,
        EncryptorInterface $encryptor,
        LoggerInterface $logger
    ) {
        $this->request = $request;
        $this->scopeConfig = $scopeConfig;
        $this->encryptor = $encryptor;
        $this->logger = $logger;
    }

    /**
     * Check if request has valid API key (non-throwing version)
     *
     * @param int $storeId
     * @return bool
     */
    public function isValidRequest(int $storeId): bool
    {
        if (!$this->isEnabled($storeId)) {
            return false;
        }

        $providedKey = $this->getApiKeyFromRequest();
        if (empty($providedKey)) {
            return false;
        }

        $configuredKey = $this->getConfiguredApiKey($storeId);
        if (empty($configuredKey)) {
            $this->logger->warning('BradSearch: Private endpoint enabled but no API key configured');
            return false;
        }

        return hash_equals($configuredKey, $providedKey);
    }

    /**
     * Check if private endpoint feature is enabled
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
     * Get API key from request header
     *
     * @return string|null
     */
    private function getApiKeyFromRequest(): ?string
    {
        $key = $this->request->getHeader(self::HEADER_NAME);
        if ($key) {
            return $key;
        }

        // Fallback for servers that prefix with HTTP_
        $serverKey = 'HTTP_' . str_replace('-', '_', strtoupper(self::HEADER_NAME));
        return $this->request->getServer($serverKey);
    }

    /**
     * Get configured API key from store config
     *
     * Handles both encrypted (admin-set) and plain-text (CLI-set) values.
     *
     * @param int $storeId
     * @return string|null
     */
    private function getConfiguredApiKey(int $storeId): ?string
    {
        $configuredKey = $this->scopeConfig->getValue(
            self::CONFIG_PATH_PRIVATE_API_KEY,
            ScopeInterface::SCOPE_STORE,
            $storeId
        );

        if (empty($configuredKey)) {
            return null;
        }

        // Try to decrypt (for admin-set encrypted values)
        $decryptedKey = $this->encryptor->decrypt($configuredKey);

        // If decryption returns a non-empty value, use it
        // Otherwise, the value was likely set via CLI as plain text
        return !empty($decryptedKey) ? $decryptedKey : $configuredKey;
    }
}
