<?php

namespace YanGusik\QueueInspector\Resolution;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\Node\Stmt\ClassMethod;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * Resolves effective configuration for a job class by reading its AST.
 * Handles: class properties, methods (backoff(), middleware(), uniqueFor()), inheritance.
 */
class JobResolver
{
    private \PhpParser\Parser $parser;
    private NodeFinder $nodeFinder;

    public function __construct(private readonly ConfigResolver $config)
    {
        $this->parser     = (new ParserFactory())->createForNewestSupportedVersion();
        $this->nodeFinder = new NodeFinder();
    }

    public function resolve(string $filePath, string $className, bool $implementsShouldBeUnique = false): ResolvedJob
    {
        $props = $this->extractFromFile($filePath);

        // If job extends another class, try to find and merge parent props
        if (!empty($props['parentClass']) && !empty($props['parentFile'])) {
            $parentProps = $this->extractFromFile($props['parentFile']);
            $props       = $this->mergeWithParent($props, $parentProps);
        }

        $connection = $props['connection'] ?? $this->config->getDefaultConnection();

        [$retryAfter, $retryAfterSource] = $this->config->getRetryAfter($connection);

        $timeout       = $props['timeout']       ?? null;
        $timeoutSource = $props['timeoutSource'] ?? null;

        if ($timeout === null && $retryAfter !== null) {
            $timeoutSource = 'queue.php (inherited, PCNTL required)';
        }

        return new ResolvedJob(
            className:                        $className,
            filePath:                         $filePath,
            timeout:                          $timeout,
            timeoutSource:                    $timeoutSource,
            retryAfter:                       $retryAfter,
            retryAfterSource:                 $retryAfterSource,
            tries:                            $props['tries']       ?? null,
            triesSource:                      $props['triesSource'] ?? null,
            hasBackoff:                       $props['hasBackoff']  ?? false,
            backoffSource:                    $props['backoffSource'] ?? null,
            hasWithoutOverlapping:            $props['hasWithoutOverlapping']            ?? false,
            withoutOverlappingHasExpireAfter: $props['withoutOverlappingHasExpireAfter'] ?? false,
            implementsShouldBeUnique:         $implementsShouldBeUnique,
            uniqueFor:                        $props['uniqueFor'] ?? null,
            usesHorizon:                      $this->config->hasHorizon(),
            connection:                       $connection,
            queue:                            $props['queue'] ?? null,
        );
    }

    private function extractFromFile(string $filePath): array
    {
        $code = file_get_contents($filePath);
        $ast  = $this->parser->parse($code);

        if (!$ast) {
            return [];
        }

        /** @var Class_|null $class */
        $class = $this->nodeFinder->findFirstInstanceOf($ast, Class_::class);
        if (!$class) {
            return [];
        }

        $props = [];

        // Resolve parent class file
        if ($class->extends) {
            $props['parentClass'] = $class->extends->toString();
            $props['parentFile']  = $this->findParentFile($filePath, $props['parentClass'], $ast);
        }

        // Scan class properties
        foreach ($class->getProperties() as $property) {
            foreach ($property->props as $prop) {
                $name  = $prop->name->toString();
                $value = $this->resolveScalarValue($prop->default);

                match ($name) {
                    'timeout' => [
                        $props['timeout']       = $value,
                        $props['timeoutSource'] = 'job class',
                    ],
                    'tries' => [
                        $props['tries']       = $value,
                        $props['triesSource'] = 'job class',
                    ],
                    'retryAfter', 'retry_after' => [
                        $props['jobRetryAfter']       = $value,
                        $props['jobRetryAfterSource'] = 'job class',
                    ],
                    'backoff' => [
                        $props['hasBackoff']    = true,
                        $props['backoffSource'] = 'job class (property)',
                    ],
                    'uniqueFor' => [
                        $props['uniqueFor'] = $value,
                    ],
                    default => null,
                };
            }
        }

        // Scan methods
        foreach ($class->getMethods() as $method) {
            $methodName = $method->name->toString();

            if ($methodName === 'backoff') {
                $props['hasBackoff']    = true;
                $props['backoffSource'] = 'job class (method)';
            }

            if ($methodName === 'retryAfter') {
                $props['jobRetryAfter']       = $this->extractReturnScalar($method);
                $props['jobRetryAfterSource'] = 'job class (method)';
            }

            if ($methodName === 'middleware') {
                $props['hasWithoutOverlapping']            = $this->methodContainsWithoutOverlapping($method);
                $props['withoutOverlappingHasExpireAfter'] = $this->withoutOverlappingHasExpireAfter($method);
            }

            if ($methodName === '__construct') {
                $this->extractConstructorQueueSettings($method, $props);
            }

            // uniqueFor() method overrides property
            if ($methodName === 'uniqueFor') {
                $value = $this->extractReturnScalar($method);
                if ($value !== null) {
                    $props['uniqueFor'] = $value;
                }
            }
        }

        return $props;
    }

    private function resolveScalarValue(?Node\Expr $expr): mixed
    {
        if ($expr === null) {
            return null;
        }

        if ($expr instanceof Node\Scalar\LNumber) {
            return $expr->value;
        }

        if ($expr instanceof Node\Scalar\String_) {
            return $expr->value;
        }

        if ($expr instanceof Node\Expr\BinaryOp\Mul) {
            $left  = $this->resolveScalarValue($expr->left);
            $right = $this->resolveScalarValue($expr->right);
            if (is_numeric($left) && is_numeric($right)) {
                return (int) ($left * $right);
            }
        }

        if ($expr instanceof Node\Expr\BinaryOp\Plus) {
            $left  = $this->resolveScalarValue($expr->left);
            $right = $this->resolveScalarValue($expr->right);
            if (is_numeric($left) && is_numeric($right)) {
                return (int) ($left + $right);
            }
        }

        return null;
    }

    private function extractReturnScalar(ClassMethod $method): mixed
    {
        $returns = $this->nodeFinder->findInstanceOf($method, Node\Stmt\Return_::class);
        foreach ($returns as $return) {
            $value = $this->resolveScalarValue($return->expr);
            if ($value !== null) {
                return $value;
            }
        }
        return null;
    }

    private function methodContainsWithoutOverlapping(ClassMethod $method): bool
    {
        $news = $this->nodeFinder->findInstanceOf($method, Node\Expr\New_::class);
        foreach ($news as $new) {
            $name = $new->class instanceof Node\Name ? $new->class->getLast() : '';
            if ($name === 'WithoutOverlapping') {
                return true;
            }
        }
        return false;
    }

    private function withoutOverlappingHasExpireAfter(ClassMethod $method): bool
    {
        $calls = $this->nodeFinder->findInstanceOf($method, Node\Expr\MethodCall::class);
        foreach ($calls as $call) {
            $name = $call->name instanceof Node\Identifier ? $call->name->toString() : '';
            if ($name === 'expireAfter' && $this->chainContainsWithoutOverlapping($call->var)) {
                return true;
            }
        }
        return false;
    }

    private function chainContainsWithoutOverlapping(Node\Expr $expr): bool
    {
        if ($expr instanceof Node\Expr\New_) {
            $name = $expr->class instanceof Node\Name ? $expr->class->getLast() : '';
            return $name === 'WithoutOverlapping';
        }
        if ($expr instanceof Node\Expr\MethodCall) {
            return $this->chainContainsWithoutOverlapping($expr->var);
        }
        return false;
    }

    private function extractConstructorQueueSettings(ClassMethod $method, array &$props): void
    {
        $calls = $this->nodeFinder->findInstanceOf($method, Node\Expr\MethodCall::class);
        foreach ($calls as $call) {
            $methodName = $call->name instanceof Node\Identifier ? $call->name->toString() : '';
            if ($methodName === 'onConnection' && isset($call->args[0])) {
                $value = $this->resolveScalarValue($call->args[0]->value);
                if ($value) {
                    $props['connection'] = $value;
                }
            }
            if ($methodName === 'onQueue' && isset($call->args[0])) {
                $value = $this->resolveScalarValue($call->args[0]->value);
                if ($value) {
                    $props['queue'] = $value;
                }
            }
        }
    }

    private function findParentFile(string $currentFile, string $parentClass, array $ast): ?string
    {
        $uses = $this->nodeFinder->findInstanceOf($ast, Node\Stmt\Use_::class);
        foreach ($uses as $use) {
            foreach ($use->uses as $useUse) {
                $alias     = $useUse->alias ? $useUse->alias->toString() : $useUse->name->getLast();
                $shortName = class_basename($parentClass) ?: $parentClass;
                if ($alias === $shortName || $alias === $parentClass) {
                    $fqcn = $useUse->name->toString();
                    $file = $this->fqcnToFile($fqcn, $currentFile);
                    if ($file && file_exists($file)) {
                        return $file;
                    }
                }
            }
        }
        return null;
    }

    private function fqcnToFile(string $fqcn, string $currentFile): ?string
    {
        $autoloadFile = $this->findAutoloadClassmap($currentFile);
        if (!$autoloadFile || !file_exists($autoloadFile)) {
            return null;
        }
        $classmap = require $autoloadFile;
        return $classmap[$fqcn] ?? null;
    }

    private function findAutoloadClassmap(string $currentFile): ?string
    {
        $dir = dirname($currentFile);
        for ($i = 0; $i < 10; $i++) {
            $candidate = $dir . '/vendor/composer/autoload_classmap.php';
            if (file_exists($candidate)) {
                return $candidate;
            }
            $parent = dirname($dir);
            if ($parent === $dir) {
                break;
            }
            $dir = $parent;
        }
        return null;
    }

    private function mergeWithParent(array $child, array $parent): array
    {
        foreach (['timeout', 'timeoutSource', 'tries', 'triesSource', 'hasBackoff', 'backoffSource', 'uniqueFor', 'hasWithoutOverlapping', 'withoutOverlappingHasExpireAfter', 'connection', 'queue'] as $key) {
            if (!isset($child[$key]) && isset($parent[$key])) {
                $child[$key] = $parent[$key];
            }
        }
        return $child;
    }
}
