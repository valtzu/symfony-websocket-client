<?php

namespace Valtzu\WebSocketClient\Tests\Bundle;

use Symfony\Bundle\FrameworkBundle\FrameworkBundle;
use Symfony\Bundle\FrameworkBundle\Kernel\MicroKernelTrait;
use Symfony\Bundle\TwigBundle\TwigBundle;
use Symfony\Bundle\WebProfilerBundle\WebProfilerBundle;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use Symfony\Component\HttpKernel\Kernel;
use Valtzu\WebSocketClient\Profiler\WebSocketDataCollector;
use Valtzu\WebSocketClient\ValtzuWebSocketClientBundle;
use Valtzu\WebSocketClient\WebSocketClient;

class TestKernel extends Kernel
{
    use MicroKernelTrait;

    public function registerBundles(): iterable
    {
        yield new FrameworkBundle();
        yield new TwigBundle();
        yield new WebProfilerBundle();
        yield new ValtzuWebSocketClientBundle();
    }

    public function getCacheDir(): string
    {
        return sys_get_temp_dir().'/websocket-client-kernel/'.($this->isDebug() ? 'debug' : 'prod');
    }

    protected function configureContainer(ContainerConfigurator $container): void
    {
        $container->extension('framework', [
            'secret' => 'test',
            'test' => true,
            'http_client' => ['enabled' => true],
            'profiler' => ['collect' => false],
        ]);
        $container->extension('twig', ['default_path' => __DIR__]);
        $container->extension('valtzu_websocket_client', ['default_options' => ['timeout' => 5]]);

        $services = $container->services();
        $services->alias('test.http_client', 'http_client')->public();
        $services->alias('test.websocket_client', WebSocketClient::class)->public();
        $services->alias('test.websocket_collector', WebSocketDataCollector::class)->public();
    }
}
