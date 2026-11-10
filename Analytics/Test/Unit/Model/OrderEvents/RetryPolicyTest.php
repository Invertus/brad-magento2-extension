<?php
/**
 * Copyright © BradSearch. All rights reserved.
 */
declare(strict_types=1);

namespace BradSearch\Analytics\Test\Unit\Model\OrderEvents;

use BradSearch\Analytics\Model\OrderEvents\RetryPolicy;
use PHPUnit\Framework\TestCase;

class RetryPolicyTest extends TestCase
{
    public function testWaitsLongerAfterEachFailureAndGivesUpAfterTheSixthRetry(): void
    {
        $policy = new RetryPolicy();

        $this->assertSame([60, 300, 900, 3600, 21600, 86400, null], array_map([$policy, 'delayAfter'], [1, 2, 3, 4, 5, 6, 7]));
    }

    public function testRetriesOnlyWhatCanHeal(): void
    {
        $policy = new RetryPolicy();

        foreach ([-1, 0, 100, 301, 401, 403, 404, 408, 425, 429, 500, 503] as $status) {
            $this->assertTrue($policy->isRetryable($status), 'status ' . $status);
        }

        foreach ([200, 400, 413, 422] as $status) {
            $this->assertFalse($policy->isRetryable($status), 'status ' . $status);
        }

        $this->assertTrue($policy->isSent(200));
        $this->assertFalse($policy->isSent(0));
    }
}
