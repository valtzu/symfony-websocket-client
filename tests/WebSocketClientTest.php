<?php

namespace Valtzu\WebSocketClient\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Component\HttpClient\TraceableHttpClient;
use Valtzu\WebSocketClient\WebSocketClient;
use Valtzu\WebSocketClient\WebSocketResponse;

class WebSocketClientTest extends TestCase
{
    use StartsEchoServer;

    protected function tearDown(): void
    {
        $this->stopServer();
    }

    #[Test]
    public function implementsHttpClientInterface()
    {
        $this->assertInstanceOf(HttpClientInterface::class, new WebSocketClient());
    }

    #[Test]
    public function echoesMessages()
    {
        $client = new WebSocketClient(['timeout' => 5]);
        $response = $client->request('GET', $this->startServer('echo'));

        $this->assertSame(101, $response->getStatusCode());
        $response->send('Hello');
        $response->send('World');

        $messages = [];
        foreach ($client->stream($response, 5) as $chunk) {
            if ($chunk->isFirst()) {
                continue;
            }
            $messages[] = $chunk->getContent();
            if (2 === count($messages)) {
                break;
            }
        }
        $this->assertSame(['Hello', 'World'], $messages);

        $response->close();
    }

    #[Test]
    public function streamsFragmentedMessagesAndAnswersPingUntilClose()
    {
        $client = new WebSocketClient(['timeout' => 5]);
        $response = $client->request('GET', $this->startServer('greet'));

        $this->assertSame('HelloWorld', $response->getContent());
        $this->assertTrue($response->isClosed());
    }

    #[Test]
    public function invalidUtf8TextIsTransportException()
    {
        $response = (new WebSocketClient(['timeout' => 5]))->request('GET', $this->startServer('invalid-utf8'));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('not valid UTF-8');
        $response->getContent();
    }

    #[Test]
    public function protocolViolationYieldsErrorChunk()
    {
        $client = new WebSocketClient(['timeout' => 5]);
        $response = $client->request('GET', $this->startServer('reserved-bits'));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage('reserved bits');
        foreach ($client->stream($response, 5) as $chunk) {
            $chunk->isFirst();
        }
    }

    #[Test]
    public function contentIsReadableThroughTraceableClient()
    {
        $client = new TraceableHttpClient(new WebSocketClient(['timeout' => 5]));
        $response = $client->request('GET', $this->startServer('greet'));

        $this->assertSame('HelloWorld', $response->getContent());
        $this->assertCount(1, $client->getTracedRequests());
    }

    #[Test]
    public function streamsThroughTraceableClient()
    {
        $client = new TraceableHttpClient(new WebSocketClient(['timeout' => 5]));
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
    public function traceableClientHidesWebSocketMethods()
    {
        $client = new TraceableHttpClient(new WebSocketClient(['timeout' => 5]));
        $response = $client->request('GET', $this->startServer('echo'));

        $this->assertNotInstanceOf(WebSocketResponse::class, $response);
        $this->assertFalse(method_exists($response, 'send'));
    }

    #[Test]
    public function timeoutChunkWhenIdle()
    {
        $client = new WebSocketClient();
        $response = $client->request('GET', $this->startServer('echo'));

        foreach ($client->stream($response, 0.2) as $chunk) {
            if ($chunk->isTimeout()) {
                $this->addToAssertionCount(1);
                return;
            }
        }
        $this->fail('No timeout chunk');
    }

    #[Test]
    public function handshakeHashMismatch()
    {
        $response = (new WebSocketClient(['timeout' => 5]))->request('GET', $this->startServer('badaccept'));

        $this->expectException(TransportException::class);
        $this->expectExceptionMessage("Received Sec-WebSocket-Accept value 'foo' did not match the expected one");
        $response->getStatusCode();
    }

    #[Test]
    public function httpErrorIsExposedAsResponse()
    {
        $response = (new WebSocketClient(['timeout' => 5]))->request('GET', $this->startServer('forbidden'));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertSame('nope', $response->getContent(false));

        $this->expectException(ClientException::class);
        $response->getContent();
    }

    #[Test]
    public function connectionFailureIsTransportException()
    {
        $response = (new WebSocketClient(['timeout' => 1]))->request('GET', 'ws://127.0.0.1:1/');

        $this->expectException(TransportException::class);
        $response->getStatusCode();
    }

    #[Test]
    public function infoUrlUsesWebSocketScheme()
    {
        $client = new WebSocketClient();

        $this->assertSame('ws://127.0.0.1:8080/socket?a=1', $client->request('GET', 'ws://127.0.0.1:8080/socket?a=1')->getInfo('url'));
        $this->assertSame('wss://example.com/', $client->request('GET', 'wss://example.com')->getInfo('url'));
    }

    #[Test]
    public function nonGetMethodIsRejected()
    {
        $this->expectException(\InvalidArgumentException::class);
        (new WebSocketClient())->request('POST', 'ws://127.0.0.1/');
    }
}
