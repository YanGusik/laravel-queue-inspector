<?php

namespace YanGusik\QueueInspector\Checks;

use YanGusik\QueueInspector\Resolution\ResolvedJob;
use YanGusik\QueueInspector\Result\CheckResult;

/**
 * Checks: timeout >= retry_after
 * If timeout is less than retry_after, the worker will re-queue the job before it finishes.
 */
class TimeoutRetryAfterCheck implements CheckInterface
{
    public function check(ResolvedJob $job): ?CheckResult
    {
        if ($job->timeout === null || $job->retryAfter === null) {
            return null;
        }

        $detail = sprintf('timeout from %s, retry_after from %s', $job->timeoutSource, $job->retryAfterSource);

        if ($job->timeout >= $job->retryAfter) {
            return CheckResult::error(
                sprintf('timeout (%ds) >= retry_after (%ds) — job will be re-queued before it finishes', $job->timeout, $job->retryAfter),
                $detail,
            );
        }

        return CheckResult::ok(
            sprintf('timeout (%ds) < retry_after (%ds) — OK', $job->timeout, $job->retryAfter),
            $detail,
        );
    }
}
