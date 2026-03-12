<?php

namespace YanGusik\QueueInspector\Checks;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;
use YanGusik\QueueInspector\Resolution\ResolvedJob;
use YanGusik\QueueInspector\Result\CheckResult;

/**
 * Checks for HTTP client usage without a timeout in the job's handle() method.
 *
 * Detection strategy (first match wins, then stop):
 *   1. If ANY ->timeout(N) method call exists in handle() → timeout is configured, no warning.
 *      This covers builder patterns: ApiClientBuilder->timeout(15), Http::timeout(30), etc.
 *   2. If no ->timeout() found, check the FIRST new Client() instantiation.
 *      If it has no 'timeout' key in the options array → warn.
 *
 * Intentionally shallow: does not recurse into services or injected dependencies.
 * Note: default values set in builder constructors (e.g. $timeout = 15) are not detected.
 */
class GuzzleTimeoutCheck implements CheckInterface
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
        if (!$code || !str_contains($code, 'timeout') && !str_contains($code, 'Client')) {
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

        $handleMethod = $this->findMethod($class, 'handle');
        $scope        = $handleMethod ?? $class;

        // Step 1: if any ->timeout(N) exists anywhere in scope → developer is handling timeouts
        if ($this->hasTimeoutMethodCall($scope)) {
            return null;
        }

        // Step 2: find the first new Client() without a timeout option
        $firstClient = $this->findFirstClientWithoutTimeout($scope);
        if ($firstClient === null) {
            return null;
        }

        return CheckResult::warning(
            'HTTP client instantiated without timeout — job may hang indefinitely',
            'best-effort: only direct new Client() in handle() is checked; injected services are not analyzed',
        );
    }

    /**
     * Check if ANY ->timeout(N) method call exists in the scope.
     * Covers: Http::timeout(30), $builder->timeout(15), $client->timeout(30)->get(), etc.
     */
    private function hasTimeoutMethodCall(Node $scope): bool
    {
        $calls = $this->nodeFinder->findInstanceOf($scope, Node\Expr\MethodCall::class);
        foreach ($calls as $call) {
            $name = $call->name instanceof Node\Identifier ? $call->name->toString() : '';
            if ($name === 'timeout' && !empty($call->args)) {
                return true;
            }
        }

        // Also check static calls: Http::timeout(30)
        $staticCalls = $this->nodeFinder->findInstanceOf($scope, Node\Expr\StaticCall::class);
        foreach ($staticCalls as $call) {
            $name = $call->name instanceof Node\Identifier ? $call->name->toString() : '';
            if ($name === 'timeout' && !empty($call->args)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Find the first new Client() / new PendingRequest() that has no 'timeout' in its options array.
     * Returns the node if found, null if all clients have timeout or none exist.
     */
    private function findFirstClientWithoutTimeout(Node $scope): ?Node\Expr\New_
    {
        $news = $this->nodeFinder->findInstanceOf($scope, Node\Expr\New_::class);
        foreach ($news as $new) {
            $name = $new->class instanceof Node\Name ? $new->class->getLast() : '';
            if (!in_array($name, ['Client', 'PendingRequest'], true)) {
                continue;
            }

            // Has options array with 'timeout' key → OK
            if (!empty($new->args)) {
                $firstArg = $new->args[0]->value ?? null;
                if ($firstArg instanceof Node\Expr\Array_ && $this->arrayHasKey($firstArg, 'timeout')) {
                    return null; // first client has timeout, stop here
                }
            }

            return $new; // first client without timeout found
        }

        return null;
    }

    private function findMethod(Class_ $class, string $name): ?ClassMethod
    {
        foreach ($class->getMethods() as $method) {
            if ($method->name->toString() === $name) {
                return $method;
            }
        }
        return null;
    }

    private function arrayHasKey(Node\Expr\Array_ $array, string $key): bool
    {
        foreach ($array->items as $item) {
            if ($item?->key instanceof Node\Scalar\String_ && $item->key->value === $key) {
                return true;
            }
        }
        return false;
    }
}
