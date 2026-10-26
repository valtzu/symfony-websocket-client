<?php

namespace Valtzu\WebSocketClient;

use Random\Randomizer;
use Symfony\Component\HttpClient\Chunk\DataChunk;
use Symfony\Component\HttpClient\Chunk\ErrorChunk;
use Symfony\Component\HttpClient\Chunk\FirstChunk;
use Symfony\Component\HttpClient\Chunk\LastChunk;
use Symfony\Component\HttpClient\Exception\InvalidArgumentException;
use Symfony\Component\HttpClient\HttpClientTrait;
use Symfony\Component\HttpClient\Response\ResponseStream;
use Symfony\Contracts\HttpClient\HttpClientInterface;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Symfony\Contracts\HttpClient\ResponseStreamInterface;

final class WebSocketClient implements HttpClientInterface
{
    use HttpClientTrait;

    private array $defaultOptions = self::OPTIONS_DEFAULTS;

    public function __construct(
        array $defaultOptions = [],
        private readonly Randomizer $randomizer = new Randomizer(),
    ) {
        if ($defaultOptions) {
            [, $this->defaultOptions] = self::prepareRequest(null, null, $this->withHttpScheme($defaultOptions), $this->defaultOptions);
        }
    }

    public function request(string $method, string $url, array $options = []): WebSocketResponse
    {
        if ('GET' !== strtoupper($method)) {
            throw new InvalidArgumentException(sprintf('WebSocket handshake requires the GET method, "%s" given.', $method));
        }

        $options = $this->withHttpScheme($options);
        [$url, $options] = self::prepareRequest($method, self::toHttpScheme($url), $options, $this->defaultOptions);

        return new WebSocketResponse($url, $options, new FrameEncoder($this->randomizer), $this->randomizer);
    }

    public function stream(ResponseInterface|iterable $responses, ?float $timeout = null): ResponseStreamInterface
    {
        if ($responses instanceof ResponseInterface) {
            $responses = [$responses];
        }

        $webSocketResponses = [];
        foreach ($responses as $response) {
            if (!$response instanceof WebSocketResponse) {
                throw new InvalidArgumentException(sprintf('%s() expects parameter 1 to be an iterable of %s objects, "%s" given.', __METHOD__, WebSocketResponse::class, get_debug_type($response)));
            }
            $webSocketResponses[] = $response;
        }

        return new ResponseStream($this->generateChunks($webSocketResponses, $timeout ?? (float) ini_get('default_socket_timeout')));
    }

    public function withOptions(array $options): static
    {
        $clone = clone $this;
        [, $clone->defaultOptions] = self::prepareRequest(null, null, $this->withHttpScheme($options), $this->defaultOptions);

        return $clone;
    }

    private function withHttpScheme(array $options): array
    {
        if (isset($options['base_uri']) && is_string($options['base_uri'])) {
            $options['base_uri'] = self::toHttpScheme($options['base_uri']);
        }

        return $options;
    }

    private static function toHttpScheme(string $url): string
    {
        return preg_replace('#^ws(s?)://#i', 'http$1://', $url);
    }

    /**
     * @param list<WebSocketResponse> $responses
     */
    private function generateChunks(array $responses, float $timeout): \Generator
    {
        $active = [];
        foreach ($responses as $response) {
            try {
                $response->connect();
            } catch (\Throwable $e) {
                yield $response => new ErrorChunk(0, $e);
                continue;
            }
            yield $response => new FirstChunk();
            $active[spl_object_id($response)] = $response;
        }

        while ($active) {
            foreach ($active as $id => $response) {
                while (null !== $message = $response->nextMessage()) {
                    yield $response => new DataChunk($response->consumedBytes(), $message);
                }
                if ($response->isClosed()) {
                    unset($active[$id]);
                    yield $response => $response->streamError()
                        ? new ErrorChunk($response->consumedBytes(), $response->streamError())
                        : new LastChunk($response->consumedBytes());
                }
            }

            if (!$active) {
                return;
            }

            $read = array_map(static fn (WebSocketResponse $response) => $response->socket(), $active);
            $write = $except = null;
            $seconds = (int) $timeout;

            if (false === $ready = @stream_select($read, $write, $except, $seconds, (int) (($timeout - $seconds) * 1e6))) {
                throw new \RuntimeException('stream_select() failed');
            }

            if (0 === $ready) {
                $first = reset($active);
                yield $first => new ErrorChunk($first->consumedBytes(), sprintf('Idle timeout reached for "%s".', $first->getInfo('url')));
                continue;
            }

            foreach ($active as $response) {
                if (in_array($response->socket(), $read, true)) {
                    $response->readAvailable();
                }
            }
        }
    }
}
