<?php

namespace YanGusik\QueueInspector;

use Illuminate\Support\ServiceProvider;
use YanGusik\QueueInspector\Commands\AnalyzeCommand;

class QueueInspectorServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->commands([
                AnalyzeCommand::class,
            ]);
        }
    }
}
