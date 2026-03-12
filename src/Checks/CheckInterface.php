<?php

namespace YanGusik\QueueInspector\Checks;

use YanGusik\QueueInspector\Resolution\ResolvedJob;
use YanGusik\QueueInspector\Result\CheckResult;

interface CheckInterface
{
    public function check(ResolvedJob $job): ?CheckResult;
}
