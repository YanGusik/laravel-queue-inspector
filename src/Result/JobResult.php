<?php

namespace YanGusik\QueueInspector\Result;

class JobResult
{
    /** @var CheckResult[] */
    private array $results = [];

    public function __construct(
        public readonly string $className,
        public readonly string $filePath,
    ) {}

    public function add(CheckResult $result): void
    {
        $this->results[] = $result;
    }

    /** @return CheckResult[] */
    public function all(): array
    {
        return $this->results;
    }

    public function hasErrors(): bool
    {
        foreach ($this->results as $result) {
            if ($result->isError()) {
                return true;
            }
        }
        return false;
    }

    public function isEmpty(): bool
    {
        return empty($this->results);
    }
}
