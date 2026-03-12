<?php

namespace YanGusik\QueueInspector;

use YanGusik\QueueInspector\Checks\CheckInterface;
use YanGusik\QueueInspector\Checks\GuzzleTimeoutCheck;
use YanGusik\QueueInspector\Checks\SleepCheck;
use YanGusik\QueueInspector\Checks\TimeoutNotSetCheck;
use YanGusik\QueueInspector\Checks\TimeoutRetryAfterCheck;
use YanGusik\QueueInspector\Checks\TriesBackoffCheck;
use YanGusik\QueueInspector\Checks\UniqueLockCheck;
use YanGusik\QueueInspector\Checks\WithoutOverlappingCheck;
use YanGusik\QueueInspector\Discovery\JobDiscovery;
use YanGusik\QueueInspector\Resolution\ConfigResolver;
use YanGusik\QueueInspector\Resolution\JobResolver;
use YanGusik\QueueInspector\Result\AnalysisResult;
use YanGusik\QueueInspector\Result\JobResult;

class Inspector
{
    private ConfigResolver $config;
    private JobResolver    $resolver;
    private JobDiscovery   $discovery;

    /** @var CheckInterface[] */
    private array $checks;

    public function __construct(
        private readonly string $projectRoot,
        bool $includeGuzzleCheck = true,
        array $excludedNamespaces = [],
    ) {
        $this->config    = new ConfigResolver($projectRoot);
        $this->resolver  = new JobResolver($this->config);
        $this->discovery = new JobDiscovery($projectRoot, $excludedNamespaces);

        $this->checks = [
            new TimeoutRetryAfterCheck(),
            new TimeoutNotSetCheck(),
            new TriesBackoffCheck(),
            new WithoutOverlappingCheck(),
            new UniqueLockCheck(),
            new SleepCheck(),
        ];

        if ($includeGuzzleCheck) {
            $this->checks[] = new GuzzleTimeoutCheck();
        }
    }

    public function analyze(): AnalysisResult
    {
        $result = new AnalysisResult();

        foreach ($this->discovery->discover() as $className => $info) {
            $resolved  = $this->resolver->resolve(
                $info['filePath'],
                $className,
                $info['implementsShouldBeUnique'],
            );

            $jobResult = new JobResult($className, $info['filePath']);

            foreach ($this->checks as $check) {
                $checkResult = $check->check($resolved);
                if ($checkResult !== null) {
                    $jobResult->add($checkResult);
                }
            }

            $result->add($jobResult);
        }

        return $result;
    }
}
