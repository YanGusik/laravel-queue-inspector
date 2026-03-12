<?php

namespace YanGusik\QueueInspector\Checks;

use YanGusik\QueueInspector\Resolution\ResolvedJob;
use YanGusik\QueueInspector\Result\CheckResult;

/**
 * Checks: tries > 1 but no backoff defined.
 * Without backoff all retries hit immediately, hammering the downstream service.
 */
class TriesBackoffCheck implements CheckInterface
{
    public function check(ResolvedJob $job): ?CheckResult
    {
        if ($job->tries === null || $job->tries <= 1) {
            return null;
        }

        if ($job->hasBackoff) {
            return null;
        }

        return CheckResult::warning(
            sprintf('tries=%d but no backoff — all retries execute immediately', $job->tries),
            sprintf('tries from %s', $job->triesSource ?? 'unknown'),
        );
    }
}
