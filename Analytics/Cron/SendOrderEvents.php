<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\Analytics\Cron;

use BradSearch\Analytics\Model\OrderEvents\OutboxSender;
use Psr\Log\LoggerInterface;

class SendOrderEvents
{
    // Leaves room in the one-minute schedule; a run stops early at its first retry anyway.
    private const BUDGET_SECONDS = 40;

    /**
     * @var OutboxSender
     */
    private OutboxSender $sender;

    /**
     * @var LoggerInterface
     */
    private LoggerInterface $logger;

    /**
     * @param OutboxSender $sender
     * @param LoggerInterface $logger
     */
    public function __construct(OutboxSender $sender, LoggerInterface $logger)
    {
        $this->sender = $sender;
        $this->logger = $logger;
    }

    public function execute(): void
    {
        try {
            $this->sender->sendDue(self::BUDGET_SECONDS);
        } catch (\Throwable $e) {
            $this->logger->warning('BradSearch Analytics: Order outbox run failed', [
                'error' => $e->getMessage(),
                'error_class' => get_class($e),
            ]);
        }
    }
}
