<?php

namespace YanGusik\QueueInspector\Checks;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use YanGusik\QueueInspector\Resolution\ResolvedJob;
use YanGusik\QueueInspector\Result\CheckResult;

/**
 * Detects sleep() / usleep() calls inside the job class and sums their literal values.
 *
 * This is an approximation — loop iterations and runtime conditions are not evaluated.
 * The sum is based only on literal integer/float arguments found in the AST.
 *
 * Checks:
 *   - totalSleep >= timeout → error (job likely cannot complete within timeout)
 *   - totalSleep > 0        → warning with approximate value
 */
class SleepCheck implements CheckInterface
{
    private \PhpParser\Parser $parser;
    private NodeFinder $nodeFinder;

    public function __construct()
    {
        $this->parser     = (new ParserFactory())->createForNewestSupportedVersion();
        $this->nodeFinder = new NodeFinder();
    }

    public function check(ResolvedJob $job): ?CheckResult
    {
        $code = @file_get_contents($job->filePath);
        if (!$code) {
            return null;
        }

        if (!str_contains($code, 'sleep')) {
            return null;
        }

        try {
            $ast = $this->parser->parse($code);
        } catch (\Throwable) {
            return null;
        }

        if (!$ast) {
            return null;
        }

        /** @var Class_|null $class */
        $class = $this->nodeFinder->findFirstInstanceOf($ast, Class_::class);
        if (!$class) {
            return null;
        }

        [$totalSeconds, $calls] = $this->collectSleepCalls($class);

        if ($totalSeconds < 1 || empty($calls)) {
            return null;
        }

        $callSummary = implode(' + ', array_map(
            fn($c) => "{$c['fn']}({$c['raw']})" . ($c['seconds'] !== $c['raw'] ? "≈{$c['seconds']}s" : 's'),
            $calls
        ));

        $detail = sprintf('calls found: %s — approximate, loop iterations not evaluated', $callSummary);

        $display = $totalSeconds >= 1 ? (int) $totalSeconds : round($totalSeconds, 2);

        if ($job->timeout !== null && $totalSeconds >= $job->timeout) {
            return CheckResult::error(
                sprintf('sleep total ~%ss >= timeout %ds — job will likely time out', $display, $job->timeout),
                $detail,
            );
        }

        // sleep < timeout — no issue
        if ($job->timeout !== null) {
            return null;
        }

        // timeout unknown — can't compare, just note it
        return CheckResult::warning(
            sprintf('sleep total ~%ss found — no timeout set, cannot verify', $display),
            $detail,
        );
    }

    /**
     * @return array{float, array<array{fn: string, raw: string, seconds: float}>}
     */
    private function collectSleepCalls(Class_ $class): array
    {
        $calls        = [];
        $totalSeconds = 0.0;

        $funcCalls = $this->nodeFinder->findInstanceOf($class, Node\Expr\FuncCall::class);

        foreach ($funcCalls as $call) {
            $name = $call->name instanceof Node\Name ? $call->name->toString() : '';

            if (!in_array($name, ['sleep', 'usleep'], true)) {
                continue;
            }

            if (empty($call->args)) {
                continue;
            }

            $argExpr = $call->args[0]->value ?? null;
            $value   = $this->resolveNumeric($argExpr);

            if ($value === null) {
                continue;
            }

            $seconds = $name === 'usleep' ? $value / 1_000_000 : $value;
            $seconds = round($seconds, 2);

            if ($seconds <= 0) {
                continue;
            }

            $totalSeconds += $seconds;
            $calls[]       = [
                'fn'      => $name,
                'raw'     => (string) $value,
                'seconds' => $seconds,
            ];
        }

        return [$totalSeconds, $calls];
    }

    private function resolveNumeric(?Node\Expr $expr): ?float
    {
        if ($expr === null) {
            return null;
        }

        if ($expr instanceof Node\Scalar\LNumber) {
            return (float) $expr->value;
        }

        if ($expr instanceof Node\Scalar\DNumber) {
            return $expr->value;
        }

        // Expressions like 5 * 60
        if ($expr instanceof Node\Expr\BinaryOp\Mul) {
            $l = $this->resolveNumeric($expr->left);
            $r = $this->resolveNumeric($expr->right);
            if ($l !== null && $r !== null) {
                return $l * $r;
            }
        }

        if ($expr instanceof Node\Expr\BinaryOp\Plus) {
            $l = $this->resolveNumeric($expr->left);
            $r = $this->resolveNumeric($expr->right);
            if ($l !== null && $r !== null) {
                return $l + $r;
            }
        }

        return null;
    }
}
