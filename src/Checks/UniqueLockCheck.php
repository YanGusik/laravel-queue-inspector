<?php

namespace YanGusik\QueueInspector\Checks;

use YanGusik\QueueInspector\Resolution\ResolvedJob;
use YanGusik\QueueInspector\Result\CheckResult;

/**
 * Checks: ShouldBeUnique jobs without uniqueFor() or with uniqueFor() = 0.
 *
 * Source: Illuminate\Bus\UniqueLock::acquire()
 *   $cache->lock($key, $uniqueFor)->get();
 *
 * When uniqueFor = 0, Redis creates the lock with no expiration (TTL=0 = permanent).
 * If the worker is killed (OOM, SIGKILL, deploy), the lock is NEVER released
 * and NEVER expires → the job can never be dispatched again until manual intervention.
 */
class UniqueLockCheck implements CheckInterface
{
    public function check(ResolvedJob $job): ?CheckResult
    {
        if (!$job->implementsShouldBeUnique) {
            return null;
        }

        if ($job->uniqueFor === null || $job->uniqueFor === 0) {
            return CheckResult::warning(
                'ShouldBeUnique without uniqueFor() — lock has no TTL, permanent on worker crash',
                'add uniqueFor(): int to set lock expiration',
            );
        }

        return CheckResult::ok(
            sprintf('ShouldBeUnique with uniqueFor=%ds — lock expires on crash', $job->uniqueFor),
            sprintf('lock key: laravel_unique_job:%s', class_basename($job->className)),
        );
    }
}
