<?php

namespace YanGusik\QueueInspector\Result;

class CheckResult
{
    const LEVEL_ERROR   = 'error';
    const LEVEL_WARNING = 'warning';
    const LEVEL_OK      = 'ok';

    public function __construct(
        public readonly string  $level,
        public readonly string  $message,
        public readonly ?string $detail = null, // shown in --verbose mode (sources, values)
    ) {}

    public static function error(string $message, ?string $detail = null): self
    {
        return new self(self::LEVEL_ERROR, $message, $detail);
    }

    public static function warning(string $message, ?string $detail = null): self
    {
        return new self(self::LEVEL_WARNING, $message, $detail);
    }

    public static function ok(string $message, ?string $detail = null): self
    {
        return new self(self::LEVEL_OK, $message, $detail);
    }

    public function isError(): bool
    {
        return $this->level === self::LEVEL_ERROR;
    }

    public function icon(): string
    {
        return match ($this->level) {
            self::LEVEL_ERROR   => '✗',
            self::LEVEL_WARNING => '⚠',
            self::LEVEL_OK      => '✓',
        };
    }
}
