<?php

namespace YanGusik\QueueInspector\Output;

use YanGusik\QueueInspector\Result\AnalysisResult;

class JsonFormatter
{
    public function format(AnalysisResult $result, bool $verbose = false): string
    {
        $output = [];

        foreach ($result->all() as $jobResult) {
            $checks = [];
            foreach ($jobResult->all() as $check) {
                $entry = [
                    'level'   => $check->level,
                    'message' => $check->message,
                ];

                if ($verbose && $check->detail !== null) {
                    $entry['detail'] = $check->detail;
                }

                $checks[] = $entry;
            }

            $output[] = [
                'class'     => $jobResult->className,
                'file'      => $jobResult->filePath,
                'hasErrors' => $jobResult->hasErrors(),
                'checks'    => $checks,
            ];
        }

        return json_encode($output, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }
}
