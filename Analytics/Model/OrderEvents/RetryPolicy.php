<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\Analytics\Model\OrderEvents;

class RetryPolicy
{
    /** Seconds to wait after each failed attempt; about 31 hours in total. */
    private const DELAYS = [60, 300, 900, 3600, 21600, 86400];
    private const PAYLOAD_ERRORS = [400, 413, 422];

    public function isSent(int $status): bool
    {
        return $status >= 200 && $status < 300;
    }

    /**
     * Only a payload the server rejected fails at once. Anything else (a timeout, a missing URL, a
     * 1xx or 3xx the client did not finish, a 401, 403 or 404 from a proxy, a 5xx) is about the
     * endpoint, hits every order alike and can clear up.
     */
    public function isRetryable(int $status): bool
    {
        return !$this->isSent($status) && !in_array($status, self::PAYLOAD_ERRORS, true);
    }

    /**
     * Answers null when the order has had its last attempt.
     */
    public function delayAfter(int $failedAttempts): ?int
    {
        return self::DELAYS[$failedAttempts - 1] ?? null;
    }
}
