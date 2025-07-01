<?php

declare(strict_types=1);

namespace PhpMcp\Server\Tests\Bridge\Symfony;

use PhpMcp\Server\Attributes\McpPrompt;
use PhpMcp\Server\Attributes\McpResource;
use PhpMcp\Server\Attributes\McpResourceTemplate;
use PhpMcp\Server\Attributes\McpTool;
use PhpMcp\Server\Bridge\Symfony\DependencyInjection\Compiler\McpServerPass;
use PhpMcp\Server\Bridge\Symfony\McpServerBundle;
use PhpMcp\Server\Contracts\McpElementInterface;
use PhpMcp\Server\Definitions\PromptDefinition;
use PhpMcp\Server\Definitions\ResourceDefinition;
use PhpMcp\Server\Definitions\ToolDefinition;
use PhpMcp\Server\Server;
use Psr\Log\NullLogger;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

function expectHasDefinition(ContainerBuilder $container, string $id): void
{
    expect($container->hasDefinition($id))->toBeTrue();
}

function expectHasAlias(ContainerBuilder $container, string $id): void
{
    expect($container->hasAlias($id))->toBeTrue();
}

#[McpPrompt(name: 'dummy_class_prompt', description: 'A dummy class prompt')]
class DummyClassPrompt implements McpElementInterface
{
    public function __invoke(): void {}
}

class DummyMethodPrompt implements McpElementInterface
{
    #[McpPrompt(name: 'dummy_method_prompt', description: 'A dummy method prompt')]
    public function prompt(): void {}
}

#[McpResource(uri: 'file:///dummy/class/resource.pdf', name: 'dummy_class_resource', description: 'A dummy class resource')]
class DummyClassResoure implements McpElementInterface
{
    public function __invoke(): void {}
}

class DummyMethodResource implements McpElementInterface
{
    #[McpResource(uri: 'file:///dummy/method/resource.pdf', name: 'dummy_method_resource', description: 'A dummy method resource')]
    public function resource(): void {}
}

#[McpResourceTemplate(uriTemplate: 'file:///home/{user}/class/resource-template', name: 'dummy_class_resource_template', description: 'A dummy class resource template')]
class DummyClassResoureTemplate implements McpElementInterface
{
    public function __invoke(): void {}
}

class DummyMethodResourceTemplate implements McpElementInterface
{
    #[McpResourceTemplate(uriTemplate: 'file:///home/{user}/method/resource-template', name: 'dummy_method_resource_template', description: 'A dummy method resource template')]
    public function resource(): void {}
}

#[McpTool(name: 'dummy_class_tool', description: 'A dummy class tool')]
class DummyClassTool implements McpElementInterface
{
    public function __invoke(): void {}
}

class DummyMethodTool implements McpElementInterface
{
    #[McpTool(name: 'dummy_method_tool', description: 'A dummy method tool')]
    public function resource(): void {}
}

class DummyElement implements McpElementInterface
{
    #[McpPrompt(name: 'dummy_method_prompt_2', description: 'Another dummy method prompt')]
    public function prompt(): void {}

    #[McpResource(uri: 'file:///dummy/method/resource-2.pdf', name: 'dummy_method_resource_2', description: 'Another dummy method resource')]
    public function resource(): void {}

    #[McpResourceTemplate(uriTemplate: 'file:///home/{user}/method/resource-template-2', name: 'dummy_method_resource_template_2', description: 'Another dummy method resource template')]
    public function resourceTemplate(): void {}

    #[McpTool(name: 'dummy_method_tool_2', description: 'Another dummy method tool')]
    public function tool(): void {}
}

it('loads bundle config', function () {
    $container = new ContainerBuilder();
    $container->setDefinition('logger', new Definition(NullLogger::class));
    foreach ([
        DummyClassPrompt::class,
        DummyMethodPrompt::class,
        DummyClassResoure::class,
        DummyMethodResource::class,
        DummyClassResoureTemplate::class,
        DummyMethodResourceTemplate::class,
        DummyClassTool::class,
        DummyMethodTool::class,
        DummyElement::class,
    ] as $element) {
        $container->setDefinition($element, (new Definition($element))->addTag('mcp_server.server_element'));
    }
    $container->setParameter('kernel.environment', 'test');
    $container->setParameter('kernel.build_dir', '');

    $bundle = new McpServerBundle();
    $bundle->getContainerExtension()->load([[
        'logger' => 'logger',
        'server_info' => [
            'name' => 'TestServer',
            'version' => '1.0.0',
        ],
    ]], $container);

    (new McpServerPass())->process($container);

    expectHasDefinition($container, 'mcp_server.server');
    expectHasAlias($container, Server::class);
    expectHasDefinition($container, 'mcp_server.command.server_start');

    $server = $container->get('mcp_server.server');

    expect($server->getConfiguration()->serverName)->toBe('TestServer');
    expect($server->getConfiguration()->serverVersion)->toBe('1.0.0');

    foreach (['dummy_class_prompt', 'dummy_method_prompt', 'dummy_method_prompt_2'] as $name) {
        expect($server->getRegistry()->findPrompt($name))->toBeInstanceOf(PromptDefinition::class);
    }
    foreach (['file:///dummy/class/resource.pdf', 'file:///dummy/method/resource.pdf', 'file:///dummy/method/resource-2.pdf'] as $name) {
        expect($server->getRegistry()->findResourceByUri($name))->toBeInstanceOf(ResourceDefinition::class);
    }
    foreach (['file:///home/{user}/class/resource-template', 'file:///home/{user}/method/resource-template', 'file:///home/{user}/method/resource-template-2'] as $name) {
        expect($server->getRegistry()->findResourceTemplateByUri($name))->toBeArray();
    }
    foreach (['dummy_class_tool', 'dummy_method_tool', 'dummy_method_tool_2'] as $name) {
        expect($server->getRegistry()->findTool($name))->toBeInstanceOf(ToolDefinition::class);
    }
});
