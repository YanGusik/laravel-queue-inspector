<?php

namespace YanGusik\QueueInspector\Output;

use YanGusik\QueueInspector\Result\AnalysisResult;
use YanGusik\QueueInspector\Result\CheckResult;

class TextFormatter
{
    public function format(AnalysisResult $result, bool $verbose = false): string
    {
        $lines = [];

        foreach ($result->all() as $jobResult) {
            if ($jobResult->isEmpty()) {
                continue;
            }

            $shortName = class_basename($jobResult->className) ?: $jobResult->className;
            $lines[]   = $shortName . ' [' . $jobResult->className . ']';

            foreach ($jobResult->all() as $check) {
                $lines[] = '  ' . $check->icon() . ' ' . $check->message;

                if ($verbose && $check->detail !== null) {
                    $lines[] = '    · ' . $check->detail;
                }
            }

            $lines[] = '';
        }

        $errors   = 0;
        $warnings = 0;
        foreach ($result->all() as $jobResult) {
            foreach ($jobResult->all() as $check) {
                if ($check->level === CheckResult::LEVEL_ERROR)   $errors++;
                if ($check->level === CheckResult::LEVEL_WARNING) $warnings++;
            }
        }

        $lines[] = sprintf(
            'Analyzed %d job(s) — %d error(s), %d warning(s)',
            $result->totalJobs(),
            $errors,
            $warnings,
        );

        return implode(PHP_EOL, $lines);
    }
}
