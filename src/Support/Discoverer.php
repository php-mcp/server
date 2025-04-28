<?php

namespace PhpMcp\Server\Support;

use PhpMcp\Server\Attributes\McpPrompt;
use PhpMcp\Server\Attributes\McpResource;
use PhpMcp\Server\Attributes\McpResourceTemplate;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Definitions\PromptDefinition;
use PhpMcp\Server\Definitions\ResourceDefinition;
use PhpMcp\Server\Definitions\ResourceTemplateDefinition;
use PhpMcp\Server\Definitions\ToolDefinition;
use PhpMcp\Server\Exceptions\McpException;
use PhpMcp\Server\Registry;
use Psr\Log\LoggerInterface;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Finder\SplFileInfo;
use Throwable;

class Discoverer
{
    private Registry $registry;

    private LoggerInterface $logger;

    private AttributeFinder $attributeFinder;

    private DocBlockParser $docBlockParser;

    private SchemaGenerator $schemaGenerator;

    public function __construct(
        Registry $registry,
        LoggerInterface $logger,
        ?AttributeFinder $attributeFinder = null,
        ?DocBlockParser $docBlockParser = null,
        ?SchemaGenerator $schemaGenerator = null
    ) {
        $this->registry = $registry;
        $this->logger = $logger;
        $this->attributeFinder = $attributeFinder ?? new AttributeFinder();
        $this->docBlockParser = $docBlockParser ?? new DocBlockParser();
        $this->schemaGenerator = $schemaGenerator ?? new SchemaGenerator($this->docBlockParser);
    }

    /**
     * Discover MCP elements in the specified directories.
     *
     * @param  string  $basePath  The base path for resolving directories.
     * @param  array<string>  $directories  List of directories (relative to base path) to scan.
     */
    public function discover(string $basePath, array $directories): void
    {
        $this->logger->debug('MCP: Starting element discovery.', ['paths' => $directories]);
        $startTime = microtime(true);

        try {
            $finder = new Finder();
            $absolutePaths = array_map(fn ($dir) => rtrim($basePath, '/').'/'.ltrim($dir, '/'), $directories);
            $existingPaths = array_filter($absolutePaths, 'is_dir');
            if (empty($existingPaths)) {
                $this->logger->warning('No valid discovery directories found.', ['paths' => $directories, 'absolute' => $absolutePaths]);

                return;
            }

            $finder->files()->in($existingPaths)->name('*.php');

            $discoveredCount = [
                'tools' => 0,
                'resources' => 0,
                'prompts' => 0,
                'resourceTemplates' => 0,
            ];

            foreach ($finder as $file) {
                $this->processFile($file, $discoveredCount);
            }

            $duration = microtime(true) - $startTime;
            $this->logger->info('MCP: Element discovery finished.', [
                'duration_sec' => round($duration, 3),
                'tools' => $discoveredCount['tools'],
                'resources' => $discoveredCount['resources'],
                'prompts' => $discoveredCount['prompts'],
                'resourceTemplates' => $discoveredCount['resourceTemplates'],
            ]);

            // Note: Caching is handled separately by calling $registry->cacheElements() after discovery.
        } catch (Throwable $e) {
            $this->logger->error('Unexpected error discovering files', ['exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
    }

    /**
     * Process a single PHP file for MCP elements.
     */
    private function processFile(SplFileInfo $file, array &$discoveredCount): void
    {
        $filePath = $file->getRealPath();
        $className = $this->getClassFromFile($filePath);

        if (! $className) {
            return;
        }

        try {
            $reflectionClass = new ReflectionClass($className);

            if ($reflectionClass->isAbstract() || $reflectionClass->isInterface() || $reflectionClass->isTrait() || $reflectionClass->isEnum()) {
                return;
            }

            foreach ($reflectionClass->getMethods(ReflectionMethod::IS_PUBLIC) as $method) {
                if ($method->isStatic() || $method->isAbstract() || $method->isConstructor() || $method->getDeclaringClass()->getName() !== $reflectionClass->getName()) {
                    continue;
                }

                $this->processMethod($method, $discoveredCount);
            }
        } catch (ReflectionException $e) {
            $this->logger->error('Reflection error discovering file', ['file' => $filePath, 'class' => $className, 'exception' => $e->getMessage()]);
        } catch (McpException $e) {
            $this->logger->error('MCP definition error', ['file' => $filePath, 'class' => $className, 'exception' => $e->getMessage()]);
        } catch (Throwable $e) {
            $this->logger->error('Unexpected error discovering file', ['file' => $filePath, 'class' => $className, 'exception' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
        }
    }

    /**
     * Process a single method for MCP attributes.
     */
    private function processMethod(ReflectionMethod $method, array &$discoveredCount): void
    {
        $className = $method->getDeclaringClass()->getName();
        $methodName = $method->getName();

        $toolAttribute = $this->attributeFinder->getFirstMethodAttribute($method, McpTool::class);
        if ($toolAttribute) {
            try {
                $instance = $toolAttribute->newInstance();
                $definition = ToolDefinition::fromReflection($method, $instance, $this->docBlockParser, $this->schemaGenerator);
                $this->registry->registerTool($definition);
                $discoveredCount['tools']++;
            } catch (Throwable $e) {
                $this->logger->error('Failed to process McpTool', ['class' => $className, 'method' => $methodName, 'exception' => $e->getMessage()]);
            }

            return;
        }

        $resourceAttribute = $this->attributeFinder->getFirstMethodAttribute($method, McpResource::class);
        if ($resourceAttribute) {
            try {
                $instance = $resourceAttribute->newInstance();
                $definition = ResourceDefinition::fromReflection($method, $instance, $this->docBlockParser);
                $this->registry->registerResource($definition);
                $discoveredCount['resources']++;
            } catch (Throwable $e) {
                $this->logger->error('Failed to process McpResource', ['class' => $className, 'method' => $methodName, 'exception' => $e->getMessage()]);
            }

            return;
        }

        $promptAttribute = $this->attributeFinder->getFirstMethodAttribute($method, McpPrompt::class);
        if ($promptAttribute) {
            try {
                $instance = $promptAttribute->newInstance();
                $definition = PromptDefinition::fromReflection($method, $instance, $this->docBlockParser);
                $this->registry->registerPrompt($definition);
                $discoveredCount['prompts']++;
            } catch (Throwable $e) {
                $this->logger->error('Failed to process McpPrompt', ['class' => $className, 'method' => $methodName, 'exception' => $e->getMessage()]);
            }

            return;
        }

        $templateAttribute = $this->attributeFinder->getFirstMethodAttribute($method, McpResourceTemplate::class);
        if ($templateAttribute) {
            try {
                $instance = $templateAttribute->newInstance();
                $definition = ResourceTemplateDefinition::fromReflection($method, $instance, $this->docBlockParser);
                $this->registry->registerResourceTemplate($definition);
                $discoveredCount['resourceTemplates']++;
            } catch (Throwable $e) {
                $this->logger->error('Failed to process McpResourceTemplate', ['class' => $className, 'method' => $methodName, 'exception' => $e->getMessage()]);
            }
        }
    }

    /**
     * Attempt to determine the FQCN from a PHP file path.
     * Uses tokenization to extract namespace and class name.
     * (Adapted from LaravelMcp\\Discovery\\McpElementScanner)
     *
     * @param  string  $filePath  Absolute path to the PHP file.
     * @return class-string|null The FQCN or null if not found/determinable.
     */
    private function getClassFromFile(string $filePath): ?string
    {
        if (! file_exists($filePath) || ! is_readable($filePath)) {
            $this->logger->warning('File does not exist or is not readable.', ['file' => $filePath]);

            return null;
        }

        try {
            $content = file_get_contents($filePath);
            if ($content === false) {
                $this->logger->warning('Failed to read file content.', ['file' => $filePath]);

                return null;
            }

            $tokens = token_get_all($content);
        } catch (Throwable $e) {
            $this->logger->warning("Failed to read or tokenize file: {$filePath}", ['exception' => $e->getMessage()]);

            return null;
        }

        $namespace = '';
        $className = null;
        $namespaceFound = false;
        $classFound = false;
        $level = 0;

        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];
            if (is_array($token)) {
                if ($token[0] === T_NAMESPACE) {
                    $namespace = '';
                    for ($j = $i + 1; $j < $count; $j++) {
                        $nextToken = $tokens[$j];
                        if (is_array($nextToken) && in_array($nextToken[0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                            continue;
                        }
                        if (is_array($nextToken) && ($nextToken[0] === T_STRING || $nextToken[0] === T_NAME_QUALIFIED || $nextToken[0] === T_NS_SEPARATOR)) {
                            $namespace .= $nextToken[1];
                        } elseif ($nextToken === ';' || $nextToken === '{') {
                            $namespaceFound = true;
                            $i = $j;
                            break;
                        } else {
                            break;
                        }
                    }
                    if ($namespaceFound) {
                        break;
                    }
                }
            }
        }

        for ($i = 0; $i < $count; $i++) {
            $token = $tokens[$i];

            if ($token === '{') {
                $level++;

                continue;
            }
            if ($token === '}') {
                $level--;

                continue;
            }

            if ($level === ($namespaceFound && str_contains($content, "namespace $namespace {") ? 1 : 0)) {
                if (is_array($token) && in_array($token[0], [T_CLASS, T_INTERFACE, T_TRAIT, defined('T_ENUM') ? T_ENUM : -1])) {
                    for ($j = $i + 1; $j < $count; $j++) {
                        if (is_array($tokens[$j])) {
                            if (in_array($tokens[$j][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                                continue;
                            }
                            if ($tokens[$j][0] === T_STRING) {
                                $className = $tokens[$j][1];
                                $classFound = true;
                                break 2;
                            }
                        }
                        break;
                    }
                }
            }
        }

        if ($classFound && $className) {
            return $namespace ? rtrim($namespace, '\\').'\\'.$className : $className;
        }

        return null;
    }
}
