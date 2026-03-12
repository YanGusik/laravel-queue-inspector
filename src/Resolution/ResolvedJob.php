<?php

namespace YanGusik\QueueInspector\Resolution;

/**
 * Resolved configuration for a single job class.
 * Each value carries the source it was taken from (job class, queue.php, horizon.php).
 */
class ResolvedJob
{
    public function __construct(
        public readonly string $className,
        public readonly string $filePath,

        // Resolved values
        public readonly ?int    $timeout,
        public readonly ?string $timeoutSource,

        public readonly ?int    $retryAfter,
        public readonly ?string $retryAfterSource,

        public readonly ?int    $tries,
        public readonly ?string $triesSource,

        public readonly bool    $hasBackoff,
        public readonly ?string $backoffSource,

        public readonly bool    $hasWithoutOverlapping,
        public readonly bool    $withoutOverlappingHasExpireAfter,

        // ShouldBeUnique: unique lock behaviour
        public readonly bool    $implementsShouldBeUnique,
        public readonly ?int    $uniqueFor,         // null = not defined / inherits 0 → no expiration!

        public readonly bool    $usesHorizon,

        // Connection/queue info
        public readonly ?string $connection,
        public readonly ?string $queue,
    ) {}
}
