<?php

namespace Valtzu\WebSocketClient\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Valtzu\WebSocketClient\WebSocketAwareHttpClient;
use Valtzu\WebSocketClient\WebSocketClient;
use Valtzu\WebSocketClient\WebSocketResponse;

class WebSocketAwareHttpClientTest extends TestCase
{
    use StartsEchoServer;

    protected function tearDown(): void
    {
        $this->stopServer();
    }

    #[Test]
    public function routesWebSocketUrlsToWebSocketClient()
    {
        $client = $this->client();

        $response = $client->request('GET', $this->startServer('echo'));

        $this->assertInstanceOf(WebSocketResponse::class, $response);
    }

    #[Test]
    public function routesOtherUrlsToDecoratedClient()
    {
        $response = $this->client()->request('GET', 'https://example.com/');

        $this->assertInstanceOf(MockResponse::class, $response);
    }

    #[Test]
    public function streamsWebSocketResponses()
    {
        $client = $this->client();
        $response = $client->request('GET', $this->startServer('greet'));

        $messages = [];
        foreach ($client->stream($response, 5) as $chunk) {
            if (!$chunk->isFirst() && !$chunk->isLast()) {
                $messages[] = $chunk->getContent();
            }
        }

        $this->assertSame(['Hello', 'World'], $messages);
    }

    #[Test]
    public function streamsHttpResponsesThroughDecoratedClient()
    {
        $client = $this->client();

        $content = '';
        foreach ($client->stream($client->request('GET', 'https://example.com/')) as $chunk) {
            $content .= $chunk->getContent();
        }

        $this->assertSame('http', $content);
    }

    #[Test]
    public function mixedStreamIsRejected()
    {
        $client = $this->client();
        $responses = [
            $client->request('GET', 'https://example.com/'),
            $client->request('GET', $this->startServer('echo')),
        ];

        $this->expectException(\LogicException::class);
        $client->stream($responses);
    }

    #[Test]
    public function withOptionsAppliesToBothClients()
    {
        $client = $this->client()->withOptions(['timeout' => 5, 'headers' => ['X-Test' => 'yes']]);

        $this->assertInstanceOf(WebSocketResponse::class, $client->request('GET', $this->startServer('echo')));
        $this->assertSame('http', $client->request('GET', 'https://example.com/')->getContent());
    }

    private function client(): WebSocketAwareHttpClient
    {
        return new WebSocketAwareHttpClient(
            new MockHttpClient(static fn () => new MockResponse('http')),
            new WebSocketClient(['timeout' => 5]),
        );
    }
}
