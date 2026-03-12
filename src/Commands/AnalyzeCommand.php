<?php

namespace YanGusik\QueueInspector\Commands;

use Illuminate\Console\Command;
use YanGusik\QueueInspector\Inspector;
use YanGusik\QueueInspector\Output\JsonFormatter;
use YanGusik\QueueInspector\Output\TextFormatter;
use YanGusik\QueueInspector\Result\CheckResult;

class AnalyzeCommand extends Command
{
    protected $signature = 'queue:analyze
        {--strict         : Exit with code 1 if any errors are found}
        {--format=text    : Output format (text|json)}
        {--v|verbose      : Show value sources (where each setting comes from)}
        {--no-guzzle      : Skip Guzzle timeout check}
        {--exclude-ns=*   : Namespaces to exclude, e.g. App\\Notifications}';

    protected $description = 'Analyze Laravel queue job configurations for common misconfigurations';

    public function handle(): int
    {
        $projectRoot       = base_path();
        $format            = $this->option('format');
        $strict            = $this->option('strict');
        $verbose           = $this->option('verbose');
        $noGuzzle          = $this->option('no-guzzle');
        $excludedNamespaces = (array) $this->option('exclude-ns');

        $inspector = new Inspector($projectRoot, !$noGuzzle, $excludedNamespaces);
        $result    = $inspector->analyze();

        if ($format === 'json') {
            $this->line((new JsonFormatter())->format($result, $verbose));
        } else {
            $output = (new TextFormatter())->format($result, $verbose);

            foreach (explode(PHP_EOL, $output) as $line) {
                if (str_contains($line, '✗')) {
                    $this->error($line);
                } elseif (str_contains($line, '⚠')) {
                    $this->warn($line);
                } elseif (str_contains($line, '✓')) {
                    $this->info($line);
                } elseif (str_starts_with(ltrim($line), '·')) {
                    $this->line('<fg=gray>' . $line . '</>');
                } else {
                    $this->line($line);
                }
            }
        }

        return ($strict && $result->hasErrors()) ? 1 : 0;
    }
}
