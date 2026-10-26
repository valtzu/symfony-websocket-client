<?php

namespace Valtzu\WebSocketClient;

use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Sends ws:// and wss:// requests to a {@see WebSocketClient} and everything else to the decorated client.
 */
final class WebSocketAwareHttpClient implements HttpClientInterface, ResetInterface
{
    public function __construct(
        private HttpClientInterface $client,
        private WebSocketClient $webSocketClient,
    ) {
    }

    public function request(string $method, string $url, array $options = []): ResponseInterface
    {
        if (preg_match('#^wss?://#i', $url)) {
            return $this->webSocketClient->request($method, $url, $options);
        }

        return $this->client->request($method, $url, $options);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        $responses = $responses instanceof ResponseInterface ? [$responses] : iterator_to_array($responses, false);
        $webSockets = array_filter($responses, static fn (ResponseInterface $response) => $response instanceof WebSocketResponse);

        if (!$webSockets) {
            return $this->client->stream($responses, $timeout);
        }
        if (count($webSockets) !== count($responses)) {
            throw new \LogicException('WebSocket and HTTP responses cannot be streamed together.');
        }

        return $this->webSocketClient->stream($webSockets, $timeout);
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        $clone->client = $this->client->withOptions($options);
        $clone->webSocketClient = $this->webSocketClient->withOptions($options);

        return $clone;
    }

    public function reset(): void
    {
        if ($this->client instanceof ResetInterface) {
            $this->client->reset();
        }
    }
}
