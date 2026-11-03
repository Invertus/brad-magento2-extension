<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\Analytics\Model\OrderEvents;

class RetryPolicy
{
    /** Seconds to wait after each failed attempt; about 1.5 days in total. */
    private const DELAYS = [60, 300, 900, 3600, 21600, 86400];

    public function isSent(int $status): bool
    {
        return $status >= 200 && $status < 300;
    }

    /**
     * Status 0 is a timeout or a network error; 1xx and 3xx are answers the client did not finish.
     */
    public function isRetryable(int $status): bool
    {
        return $status === 0
            || ($status >= 100 && $status < 200)
            || ($status >= 300 && $status < 400)
            || in_array($status, [408, 425, 429], true)
            || $status >= 500;
    }

    /**
     * Answers null when the order has had its last attempt.
     */
    public function delayAfter(int $failedAttempts): ?int
    {
        return self::DELAYS[$failedAttempts - 1] ?? null;
    }
}
