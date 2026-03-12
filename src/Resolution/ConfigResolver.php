<?php

namespace YanGusik\QueueInspector\Resolution;

/**
 * Reads queue.php and horizon.php from a Laravel project root.
 * Returns raw config arrays — no Laravel bootstrap required.
 */
class ConfigResolver
{
    private array $queueConfig  = [];
    private array $horizonConfig = [];

    public function __construct(private readonly string $projectRoot)
    {
        $this->load();
    }

    private function load(): void
    {
        $queueFile  = $this->projectRoot . '/config/queue.php';
        $horizonFile = $this->projectRoot . '/config/horizon.php';

        if (file_exists($queueFile)) {
            $this->queueConfig = require $queueFile;
        }

        if (file_exists($horizonFile)) {
            $this->horizonConfig = require $horizonFile;
        }
    }

    public function hasHorizon(): bool
    {
        return !empty($this->horizonConfig);
    }

    public function getDefaultConnection(): string
    {
        return $this->queueConfig['default'] ?? 'sync';
    }

    /**
     * Get retry_after for a connection.
     * Returns [value, source] or [null, null].
     */
    public function getRetryAfter(string $connection): array
    {
        // Check horizon supervisors first
        if ($this->hasHorizon()) {
            $horizonValue = $this->getHorizonRetryAfter($connection);
            if ($horizonValue !== null) {
                return [$horizonValue, 'horizon.php'];
            }
        }

        $value = $this->queueConfig['connections'][$connection]['retry_after'] ?? null;
        if ($value !== null) {
            return [(int) $value, 'queue.php'];
        }

        return [null, null];
    }

    /**
     * Horizon stores supervisor config per environment.
     * We scan all environments for a matching queue/connection.
     */
    private function getHorizonRetryAfter(string $connection): ?int
    {
        $environments = $this->horizonConfig['environments'] ?? [];

        foreach ($environments as $supervisors) {
            foreach ($supervisors as $supervisor) {
                if (isset($supervisor['connection']) && $supervisor['connection'] === $connection) {
                    if (isset($supervisor['retry_after'])) {
                        return (int) $supervisor['retry_after'];
                    }
                }
            }
        }

        return null;
    }

    public function getQueueConfig(): array
    {
        return $this->queueConfig;
    }

    public function getHorizonConfig(): array
    {
        return $this->horizonConfig;
    }
}
