<?php

use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Valtzu\WebSocketClient\WebSocketAwareHttpClient;
use Valtzu\WebSocketClient\WebSocketClient;

use function Symfony\Component\DependencyInjection\Loader\Configurator\service;

return static function (ContainerConfigurator $container): void {
    $services = $container->services();

    $services->set(WebSocketClient::class)
        ->arg('$defaultOptions', []);

    $services->set(WebSocketAwareHttpClient::class)
        ->decorate('http_client', null, -100, ContainerInterface::IGNORE_ON_INVALID_REFERENCE)
        ->args([service('.inner'), service(WebSocketClient::class)]);
};
