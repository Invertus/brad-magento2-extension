<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\Analytics\Model\OrderEvents;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Lock\LockManagerInterface;
use Magento\Store\Model\ScopeInterface;
use Psr\Log\LoggerInterface;

class OutboxSender
{
    public const BATCH_SIZE = 50;

    private const CONFIG_PATH_API_URL = 'bradsearch_analytics/general/api_url';
    private const LOCK_NAME = 'bradsearch_order_outbox';
    // Checkout never waits for this, so a slow but working server gets time to answer.
    private const TIMEOUT_SECONDS = 10;
    /** A run that dies leaves its row leased this long, then the row is due again. */
    private const LEASE_SECONDS = 120;
    private const SENT_KEEP_DAYS = 7;
    private const FAILED_KEEP_DAYS = 30;
    // No URL is an endpoint problem like any other: the order waits for it.
    private const STATUS_NOT_CONFIGURED = -1;
    // Browser-like UA: the analytics service drops events whose UA looks like a bot.
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 6.1) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/41.0.2228.0 Safari/537.36';

    /**
     * @var Outbox
     */
    private Outbox $outbox;

    /**
     * @var CurlFactory
     */
    private CurlFactory $curlFactory;

    /**
     * @var ScopeConfigInterface
     */
    private ScopeConfigInterface $scopeConfig;

    /**
     * @var LockManagerInterface
     */
    private LockManagerInterface $lockManager;

    /**
     * @var RetryPolicy
     */
    private RetryPolicy $retryPolicy;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @var callable
     */
    private $now;

    /**
     * @param Outbox $outbox
     * @param CurlFactory $curlFactory
     * @param ScopeConfigInterface $scopeConfig
     * @param LockManagerInterface $lockManager
     * @param RetryPolicy $retryPolicy
     * @param LoggerInterface $logger
     * @param callable|null $now
     */
    public function __construct(
        Outbox $outbox,
        CurlFactory $curlFactory,
        ScopeConfigInterface $scopeConfig,
        LockManagerInterface $lockManager,
        RetryPolicy $retryPolicy,
        LoggerInterface $logger,
        ?callable $now = null
    ) {
        $this->outbox = $outbox;
        $this->curlFactory = $curlFactory;
        $this->scopeConfig = $scopeConfig;
        $this->lockManager = $lockManager;
        $this->retryPolicy = $retryPolicy;
        $this->logger = $logger;
        $this->now = $now ?? 'time';
    }

    /**
     * Sends due orders oldest first; one run at a time, and a run stops at its first retry.
     *
     * @return array{sent: int, retry: int, failed: int}
     */
    public function sendDue(int $budgetSeconds, int $limit = self::BATCH_SIZE): array
    {
        $result = ['sent' => 0, 'retry' => 0, 'failed' => 0];

        if (!$this->lockManager->lock(self::LOCK_NAME, 0)) {
            return $result;
        }

        try {
            $deadline = $this->clock() + $budgetSeconds;

            for ($i = 0; $i < $limit && $this->clock() < $deadline; $i++) {
                $now = $this->clock();
                $row = $this->outbox->claimNext($this->format($now), $this->format($now + self::LEASE_SECONDS));

                if ($row === null) {
                    break;
                }

                $outcome = $this->sendRow($row);
                $result[$outcome]++;

                if ($outcome === 'retry') {
                    break;
                }
            }

            $now = $this->clock();
            $this->outbox->purge(
                $this->format($now - self::SENT_KEEP_DAYS * 86400),
                $this->format($now - self::FAILED_KEEP_DAYS * 86400)
            );
        } finally {
            $this->lockManager->unlock(self::LOCK_NAME);
        }

        return $result;
    }

    /**
     * @param array{entity_id: int, order_id: int, store_id: int, body: string, attempts: int, lease: string} $row
     */
    private function sendRow(array $row): string
    {
        $attempts = $row['attempts'] + 1;
        [$status, $error] = $this->post($row['store_id'], $row['body']);
        $now = $this->clock();

        if ($this->retryPolicy->isSent($status)) {
            $this->outbox->markSent($row['entity_id'], $row['lease'], $attempts, $this->format($now));

            return 'sent';
        }

        $delay = $this->retryPolicy->isRetryable($status) ? $this->retryPolicy->delayAfter($attempts) : null;

        if ($delay !== null) {
            $this->outbox->retryLater($row['entity_id'], $row['lease'], $attempts, $this->format($now + $delay), $error, $this->format($now));
            $this->logger->debug('BradSearch Analytics: Order-paid event not sent, will retry', [
                'order_id' => $row['order_id'],
                'attempts' => $attempts,
                'retry_in_seconds' => $delay,
                'error' => $error,
            ]);

            return 'retry';
        }

        $this->outbox->markFailed($row['entity_id'], $row['lease'], $attempts, $error, $this->format($now));
        $this->logger->warning('BradSearch Analytics: Order-paid event could not be sent and is given up', [
            'order_id' => $row['order_id'],
            'attempts' => $attempts,
            'last_error' => $error,
        ]);

        return 'failed';
    }

    /**
     * @return array{0: int, 1: string} the HTTP status (0 on a timeout or network error) and an error text
     */
    private function post(int $storeId, string $body): array
    {
        try {
            $apiUrl = trim((string) $this->scopeConfig->getValue(self::CONFIG_PATH_API_URL, ScopeInterface::SCOPE_STORE, $storeId));
        } catch (\Throwable $e) {
            // A store deleted after its order was queued has no config any more.
            $apiUrl = '';
        }

        if ($apiUrl === '') {
            return [self::STATUS_NOT_CONFIGURED, 'Analytics API URL is not set'];
        }

        $apiUrl = rtrim($apiUrl, '/');
        $endpoint = strpos($apiUrl, '/api/send') !== false ? $apiUrl : $apiUrl . '/api/send';

        $curl = $this->curlFactory->create();
        $curl->addHeader('Content-Type', 'application/json');
        $curl->addHeader('User-Agent', self::USER_AGENT);
        // Without it, curl sends Expect: 100-continue for bodies over 1 KB and the client reports status 100.
        $curl->addHeader('Expect', '');
        $curl->setOption(CURLOPT_TIMEOUT, self::TIMEOUT_SECONDS);
        $curl->setOption(CURLOPT_CONNECTTIMEOUT, self::TIMEOUT_SECONDS);

        try {
            $curl->post($endpoint, $body);
        } catch (\Throwable $e) {
            return [0, get_class($e) . ': ' . $e->getMessage()];
        }

        $status = (int) $curl->getStatus();

        return [$status, $this->retryPolicy->isSent($status) ? '' : 'HTTP ' . $status];
    }

    private function clock(): int
    {
        return (int) call_user_func($this->now);
    }

    private function format(int $timestamp): string
    {
        return gmdate('Y-m-d H:i:s', $timestamp);
    }
}
