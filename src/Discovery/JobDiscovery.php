<?php

namespace YanGusik\QueueInspector\Discovery;

use PhpParser\Node;
use PhpParser\Node\Stmt\Class_;
use PhpParser\NodeFinder;
use PhpParser\ParserFactory;

/**
 * Discovers all classes implementing ShouldQueue in a Laravel project.
 * Uses composer autoload classmap — no reflection, no Laravel bootstrap.
 *
 * Ignores classes marked with (delete '.' sym):
 *   - @.deprecated or @queue-inspector-ignore in docblock
 *   - #.[Deprecated] attribute (JetBrains\PhpStorm\Deprecated or native PHP 8.4)
 *   - #.[QueueInspectorIgnore] attribute
 */
class JobDiscovery
{
    private \PhpParser\Parser $parser;
    private NodeFinder $nodeFinder;

    /** @var string[] Namespaces to exclude from discovery (configurable) */
    private array $excludedNamespaces;

    public function __construct(
        private readonly string $projectRoot,
        array $excludedNamespaces = [],
    ) {
        $this->parser             = (new ParserFactory())->createForNewestSupportedVersion();
        $this->nodeFinder         = new NodeFinder();
        $this->excludedNamespaces = $excludedNamespaces;
    }

    /**
     * @return array<string, array{filePath: string, implementsShouldBeUnique: bool}>
     */
    public function discover(): array
    {
        $classmap = $this->loadClassmap();
        if (empty($classmap)) {
            return [];
        }

        $jobs = [];
        foreach ($classmap as $className => $filePath) {
            if (!str_starts_with($filePath, $this->projectRoot . '/app')) {
                continue;
            }

            if (!file_exists($filePath)) {
                continue;
            }

            if ($this->isExcludedNamespace($className)) {
                continue;
            }

            $info = $this->analyzeFile($filePath);

            if (!$info['implementsShouldQueue']) {
                continue;
            }

            if ($info['isIgnored']) {
                continue;
            }

            $jobs[$className] = [
                'filePath'                => $filePath,
                'implementsShouldBeUnique' => $info['implementsShouldBeUnique'],
            ];
        }

        return $jobs;
    }

    private function loadClassmap(): array
    {
        $classmapFile = $this->projectRoot . '/vendor/composer/autoload_classmap.php';

        if (!file_exists($classmapFile)) {
            return [];
        }

        return require $classmapFile;
    }

    /**
     * @return array{implementsShouldQueue: bool, implementsShouldBeUnique: bool, isIgnored: bool}
     */
    private function analyzeFile(string $filePath): array
    {
        $result = [
            'implementsShouldQueue'    => false,
            'implementsShouldBeUnique' => false,
            'isIgnored'                => false,
        ];

        $code = @file_get_contents($filePath);
        if (!$code) {
            return $result;
        }

        if (!str_contains($code, 'ShouldQueue')) {
            return $result;
        }

        try {
            $ast = $this->parser->parse($code);
        } catch (\Throwable) {
            return $result;
        }

        if (!$ast) {
            return $result;
        }

        /** @var Class_|null $class */
        $class = $this->nodeFinder->findFirstInstanceOf($ast, Class_::class);
        if (!$class) {
            return $result;
        }

        foreach ($class->implements as $interface) {
            $name = $interface->getLast();
            if ($name === 'ShouldQueue') {
                $result['implementsShouldQueue'] = true;
            }
            if ($name === 'ShouldBeUnique' || $name === 'ShouldBeUniqueUntilProcessing') {
                $result['implementsShouldBeUnique'] = true;
            }
        }

        if (!$result['implementsShouldQueue']) {
            return $result;
        }

        $result['isIgnored'] = $this->isIgnored($class);

        return $result;
    }

    private function isIgnored(Class_ $class): bool
    {
        // 1. Check PHPDoc: @deprecated or @queue-inspector-ignore
        $docComment = $class->getDocComment();
        if ($docComment !== null) {
            $text = $docComment->getText();
            if (
                str_contains($text, '@deprecated') ||
                str_contains($text, '@queue-inspector-ignore')
            ) {
                return true;
            }
        }

        // 2. Check PHP attributes: #[Deprecated] (JetBrains / native PHP 8.4) or #[QueueInspectorIgnore]
        foreach ($class->attrGroups as $attrGroup) {
            foreach ($attrGroup->attrs as $attr) {
                $name = $attr->name instanceof Node\Name ? $attr->name->getLast() : '';
                if ($name === 'Deprecated' || $name === 'QueueInspectorIgnore') {
                    return true;
                }
            }
        }

        return false;
    }

    private function isExcludedNamespace(string $className): bool
    {
        foreach ($this->excludedNamespaces as $ns) {
            if (str_starts_with($className, $ns)) {
                return true;
            }
        }
        return false;
    }
}
