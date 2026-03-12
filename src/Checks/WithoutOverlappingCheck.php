<?php

namespace YanGusik\QueueInspector\Checks;

use YanGusik\QueueInspector\Resolution\ResolvedJob;
use YanGusik\QueueInspector\Result\CheckResult;

/**
 * Checks: WithoutOverlapping is used without ->expireAfter().
 *
 * Without expireAfter(), if a worker is killed (deploy, OOM, crash),
 * the atomic lock is never released and the job is blocked until
 * the lock TTL expires — which defaults to forever in some cache drivers.
 *
 * This is NOT a Horizon-specific issue — it affects all queue drivers.
 */
class WithoutOverlappingCheck implements CheckInterface
{
    public function check(ResolvedJob $job): ?CheckResult
    {
        if (!$job->hasWithoutOverlapping) {
            return null;
        }

        if ($job->withoutOverlappingHasExpireAfter) {
            return CheckResult::ok('WithoutOverlapping with expireAfter() — lock will release on worker crash');
        }

        return CheckResult::warning(
            'WithoutOverlapping without expireAfter() — if worker crashes, lock may never release (add ->expireAfter(seconds))'
        );
    }
}
