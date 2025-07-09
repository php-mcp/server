<?php

declare(strict_types=1);

namespace PhpMcp\Server\Bridge\Symfony\DependencyInjection\Compiler;

use PhpMcp\Server\Attributes\McpPrompt;
use PhpMcp\Server\Attributes\McpResource;
use PhpMcp\Server\Attributes\McpResourceTemplate;
use PhpMcp\Server\Attributes\McpTool;
use Symfony\Component\DependencyInjection\Compiler\CompilerPassInterface;
use Symfony\Component\DependencyInjection\Compiler\ServiceLocatorTagPass;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Exception\LogicException;
use Symfony\Component\DependencyInjection\Reference;

class McpServerPass implements CompilerPassInterface
{
    public function process(ContainerBuilder $container): void
    {
        $serverBuilderDefinition = $container->getDefinition('mcp_server.server_builder');

        $mcpElements = [];

        foreach ($container->findTaggedServiceIds('mcp_server.server_element') as $serviceId => $tags) {
            $definition = $container->findDefinition($serviceId);

            $mcpElements[$definition->getClass()] = new Reference($serviceId);

            $reflectionClass = new \ReflectionClass($definition->getClass());

            foreach ([McpPrompt::class, McpResource::class, McpResourceTemplate::class, McpTool::class] as $attributeClass) {
                if ([] !== $reflectionAttributes = $reflectionClass->getAttributes($attributeClass, \ReflectionAttribute::IS_INSTANCEOF)) {
                    if (!$reflectionClass->hasMethod('__invoke')) {
                        throw new LogicException(sprintf('The class "%s" has attribute "%s" but method "__invoke" is missing, please declare it.', $definition->getClass(), $attributeClass));
                    }

                    $this->mapElements($attributeClass, $serverBuilderDefinition, [$definition->getClass(), '__invoke'], $reflectionAttributes[0]->getArguments());

                    break;
                }

                foreach ($reflectionClass->getMethods() as $reflectionMethod) {
                    if ([] !== $reflectionAttributes = $reflectionMethod->getAttributes($attributeClass, \ReflectionAttribute::IS_INSTANCEOF)) {
                        $this->mapElements($attributeClass, $serverBuilderDefinition, [$definition->getClass(), $reflectionMethod->getName()], $reflectionAttributes[0]->getArguments());
                    }
                }
            }
        }

        $serverBuilderDefinition->addMethodCall('withContainer', [ServiceLocatorTagPass::register($container, $mcpElements)]);
    }

    private function mapElements(string $attributeClass, Definition $serverBuilderDefinition, array $handler, array $attributeArgs): void
    {
        match ($attributeClass) {
            McpPrompt::class => $serverBuilderDefinition->addMethodCall('withPrompt', [
                $handler,
                $attributeArgs['name'] ?? null,
                $attributeArgs['description'] ?? null,
            ]),
            McpResource::class => $serverBuilderDefinition->addMethodCall('withResource', [
                $handler,
                $attributeArgs['uri'] ?? null,
                $attributeArgs['name'] ?? null,
                $attributeArgs['description'] ?? null,
                $attributeArgs['mimeType'] ?? null,
                $attributeArgs['size'] ?? null,
                $attributeArgs['annotations'] ?? [],
            ]),
            McpResourceTemplate::class => $serverBuilderDefinition->addMethodCall('withResourceTemplate', [
                $handler,
                $attributeArgs['uriTemplate'] ?? null,
                $attributeArgs['name'] ?? null,
                $attributeArgs['description'] ?? null,
                $attributeArgs['mimeType'] ?? null,
                $attributeArgs['annotations'] ?? [],
            ]),
            McpTool::class => $serverBuilderDefinition->addMethodCall('withTool', [
                $handler,
                $attributeArgs['name'] ?? null,
                $attributeArgs['description'] ?? null,
            ]),
        };
    }
}
