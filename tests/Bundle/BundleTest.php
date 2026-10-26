<?php

namespace Valtzu\WebSocketClient\Tests\Bundle;

use PHPUnit\Framework\Attributes\Test;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Valtzu\WebSocketClient\Tests\StartsEchoServer;
use Valtzu\WebSocketClient\WebSocketAwareHttpClient;
use Valtzu\WebSocketClient\WebSocketResponse;

class BundleTest extends KernelTestCase
{
    use StartsEchoServer;

    protected static function getKernelClass(): string
    {
        return TestKernel::class;
    }

    protected function tearDown(): void
    {
        $this->stopServer();
        parent::tearDown();
    }

    #[Test]
    public function httpClientIsWebSocketAware()
    {
        $this->assertInstanceOf(WebSocketAwareHttpClient::class, self::getContainer()->get('test.http_client'));
    }

    #[Test]
    public function webSocketRequestsViaHttpClientReturnWebSocketResponse()
    {
        $response = self::getContainer()->get('test.http_client')->request('GET', $this->startServer('echo'));

        $this->assertInstanceOf(WebSocketResponse::class, $response);
        $response->send('ping');
        $response->close();
    }

    #[Test]
    public function profilerCollectsConnections()
    {
        $client = self::getContainer()->get('test.websocket_client');
        $response = $client->request('GET', $this->startServer('echo'));
        $response->send('Hello');
        foreach ($client->stream($response, 5) as $chunk) {
            if (!$chunk->isFirst()) {
                break;
            }
        }
        $response->close();

        $collector = self::getContainer()->get('test.websocket_collector');
        $collector->lateCollect();

        $this->assertCount(1, $collector->getConnections());
        $this->assertSame(101, $collector->getConnections()[0]['status']);
        $this->assertSame(['out', 'in', 'out'], array_column($collector->getConnections()[0]['frames'], 'direction'));
        $this->assertSame('Hello', $collector->getConnections()[0]['frames'][0]['preview']);
    }
}
