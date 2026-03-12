<?php

namespace YanGusik\QueueInspector\Result;

class AnalysisResult
{
    /** @var JobResult[] */
    private array $jobs = [];

    public function add(JobResult $job): void
    {
        $this->jobs[] = $job;
    }

    /** @return JobResult[] */
    public function all(): array
    {
        return $this->jobs;
    }

    public function hasErrors(): bool
    {
        foreach ($this->jobs as $job) {
            if ($job->hasErrors()) {
                return true;
            }
        }
        return false;
    }

    public function totalJobs(): int
    {
        return count($this->jobs);
    }
}
