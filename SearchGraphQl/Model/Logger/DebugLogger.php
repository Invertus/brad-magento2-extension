<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */

declare(strict_types=1);

namespace BradSearch\SearchGraphQl\Model\Logger;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

/**
 * Logger decorator that suppresses debug/info messages unless debug logging is enabled in admin.
 */
class DebugLogger implements LoggerInterface
{
    private const CONFIG_PATH_DEBUG_LOGGING = 'bradsearch_search/general/debug_logging';

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @param LoggerInterface $logger
     * @param ScopeConfigInterface $scopeConfig
     */
    public function __construct(
        LoggerInterface $logger,
        ScopeConfigInterface $scopeConfig
    ) {
        $this->logger = $logger;
        $this->scopeConfig = $scopeConfig;
    }

    /**
     * @return bool
     */
    private function isDebugEnabled(): bool
    {
        return $this->scopeConfig->isSetFlag(
            self::CONFIG_PATH_DEBUG_LOGGING,
            ScopeInterface::SCOPE_STORE
        );
    }

    /**
     * @inheritdoc
     */
    public function emergency($message, array $context = []): void
    {
        $this->logger->emergency($message, $context);
    }

    /**
     * @inheritdoc
     */
    public function alert($message, array $context = []): void
    {
        $this->logger->alert($message, $context);
    }

    /**
     * @inheritdoc
     */
    public function critical($message, array $context = []): void
    {
        $this->logger->critical($message, $context);
    }

    /**
     * @inheritdoc
     */
    public function error($message, array $context = []): void
    {
        $this->logger->error($message, $context);
    }

    /**
     * @inheritdoc
     */
    public function warning($message, array $context = []): void
    {
        $this->logger->warning($message, $context);
    }

    /**
     * @inheritdoc
     */
    public function notice($message, array $context = []): void
    {
        if ($this->isDebugEnabled()) {
            $this->logger->notice($message, $context);
        }
    }

    /**
     * @inheritdoc
     */
    public function info($message, array $context = []): void
    {
        if ($this->isDebugEnabled()) {
            $this->logger->info($message, $context);
        }
    }

    /**
     * @inheritdoc
     */
    public function debug($message, array $context = []): void
    {
        if ($this->isDebugEnabled()) {
            $this->logger->debug($message, $context);
        }
    }

    /**
     * @inheritdoc
     */
    public function log($level, $message, array $context = []): void
    {
        // PSR-3 log levels that should always pass through
        $alwaysLogLevels = ['emergency', 'alert', 'critical', 'error', 'warning'];

        if (in_array($level, $alwaysLogLevels, true) || $this->isDebugEnabled()) {
            $this->logger->log($level, $message, $context);
        }
    }
}
