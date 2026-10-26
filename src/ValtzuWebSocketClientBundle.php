<?php

namespace Valtzu\WebSocketClient;

use Symfony\Component\Config\Definition\Configurator\DefinitionConfigurator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Reference;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Bundle\AbstractBundle;
use Valtzu\WebSocketClient\Profiler\WebSocketTraceLog;

final class ValtzuWebSocketClientBundle extends AbstractBundle
{
    protected string $extensionAlias = 'valtzu_websocket_client';

    public function configure(DefinitionConfigurator $definition): void
    {
        $definition->rootNode()
            ->children()
                ->arrayNode('default_options')
                    ->info('Default options of the WebSocketClient, same as for the HttpClient.')
                    ->variablePrototype()->end()
                ->end()
            ->end();
    }

    public function loadExtension(array $config, ContainerConfigurator $container, ContainerBuilder $builder): void
    {
        $container->import('../config/services.php');
        $builder->getDefinition(WebSocketClient::class)->setArgument('$defaultOptions', $config['default_options']);

        if ($builder->getParameter('kernel.debug')) {
            $container->import('../config/profiler.php');
            $builder->getDefinition(WebSocketClient::class)->setArgument('$traceLog', new Reference(WebSocketTraceLog::class));
        }
    }
}
