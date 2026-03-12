<?php

namespace YanGusik\QueueInspector\Checks;

use YanGusik\QueueInspector\Resolution\ResolvedJob;
use YanGusik\QueueInspector\Result\CheckResult;

/**
 * Checks: timeout is not set on the job class.
 * In that case Laravel inherits the value from queue connection config,
 * and process termination requires PCNTL extension.
 */
class TimeoutNotSetCheck implements CheckInterface
{
    public function check(ResolvedJob $job): ?CheckResult
    {
        if ($job->timeout !== null) {
            return null;
        }

        $inherited = $job->retryAfter
            ? sprintf('%ds from %s', $job->retryAfter, $job->retryAfterSource)
            : 'no retry_after found either';

        return CheckResult::warning(
            sprintf('timeout not set — inherits from connection "%s", PCNTL required for termination', $job->connection),
            sprintf('retry_after: %s', $inherited),
        );
    }
}
