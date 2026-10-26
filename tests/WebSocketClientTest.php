<?php

namespace Valtzu\WebSocketClient\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Valtzu\WebSocketClient\WebSocketClient;

class WebSocketClientTest extends TestCase
{
    /** @var resource|null */
    private $process = null;

    protected function tearDown(): void
    {
        if ($this->process) {
            proc_terminate($this->process);
            proc_close($this->process);
        }
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
    public function nonGetMethodIsRejected()
    {
        $this->expectException(\InvalidArgumentException::class);
        (new WebSocketClient())->request('POST', 'ws://127.0.0.1/');
    }

    private function startServer(string $mode): string
    {
        $this->process = proc_open(
            [PHP_BINARY, __DIR__.'/Fixtures/echo-server.php', $mode],
            [1 => ['pipe', 'w']],
            $pipes,
        );
        $port = (int) trim(fgets($pipes[1]));

        return "ws://127.0.0.1:$port/socket?a=1";
    }
}
