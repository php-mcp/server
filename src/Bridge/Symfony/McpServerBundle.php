<?php

declare(strict_types=1);

namespace PhpMcp\Server\Bridge\Symfony;

use PhpMcp\Server\Bridge\Symfony\Command\McpServerStartCommand;
use PhpMcp\Server\Bridge\Symfony\DependencyInjection\Compiler\McpServerPass;
use PhpMcp\Server\Contracts\McpElementInterface;
use PhpMcp\Server\Server;
use PhpMcp\Server\ServerBuilder;
use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

class McpServerBundle extends AbstractBundle
{
    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->scalarNode('logger')->cannotBeEmpty()->defaultValue('logger')->end()
                ->arrayNode('server_info')
                    ->isRequired()
                    ->children()
                        ->scalarNode('name')->isRequired()->cannotBeEmpty()->end()
                        ->scalarNode('version')->isRequired()->cannotBeEmpty()->end()
                    ->end()
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->services()
            ->set('mcp_server.server_builder', ServerBuilder::class)
                ->factory([Server::class, 'make'])
                ->call('withLogger', [service($config['logger'])])
                ->call('withServerInfo', [$config['server_info']['name'], $config['server_info']['version']])

            ->set('mcp_server.server', Server::class)
                ->factory([service('mcp_server.server_builder'), 'build'])
            ->alias(Server::class, 'mcp_server.server')

            ->set('mcp_server.command.server_start', McpServerStartCommand::class)
                ->args([service('mcp_server.server')])
                ->tag('console.command');

        $builder->registerForAutoconfiguration(McpElementInterface::class)->addTag('mcp_server.server_element');
    }

    public function build(ContainerBuilder $container): void
    {
        parent::build($container);

        $container->addCompilerPass(new McpServerPass());
    }
}
