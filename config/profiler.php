<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Valtzu\WebSocketClient\Profiler\WebSocketDataCollector;
use Valtzu\WebSocketClient\Profiler\WebSocketTraceLog;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(WebSocketTraceLog::class)
        ->tag('kernel.reset', ['method' => 'reset']);

    $services->set(WebSocketDataCollector::class)
        ->args([service(WebSocketTraceLog::class)])
        ->tag('data_collector', ['id' => 'websocket', 'template' => '@ValtzuWebSocketClient/data_collector/websocket.html.twig']);
};
