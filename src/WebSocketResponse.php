<?php

namespace Valtzu\WebSocketClient;

use Random\Randomizer;
use Symfony\Component\HttpClient\Exception\ClientException;
use Symfony\Component\HttpClient\Exception\JsonException;
use Symfony\Component\HttpClient\Exception\RedirectionException;
use Symfony\Component\HttpClient\Exception\ServerException;
use Symfony\Component\HttpClient\Exception\TransportException;
use Symfony\Contracts\HttpClient\ResponseInterface;
use Valtzu\WebSocketClient\Profiler\WebSocketTrace;
use Valtzu\WebSocketClient\Profiler\WebSocketTraceLog;

final class WebSocketResponse implements ResponseInterface
{
    private const HANDSHAKE_GUID = '258EAFA5-E914-47DA-95CA-C5AB0DC85B11';

    /** @var resource|null */
    private $socket = null;
    private ?int $statusCode = null;
    /** @var array<string, list<string>> */
    private array $headers = [];
    /** @var list<string> */
    private array $headerLines = [];
    private string $body = '';
    private FrameParser $parser;
    /** @var list<string> */
    private array $messages = [];
    private string $fragments = '';
    private ?int $fragmentOpcode = null;
    private int $consumedBytes = 0;
    private bool $closed = false;
    private bool $canceled = false;
    private ?\Throwable $error = null;
    private ?TransportException $streamError = null;
    private float $startTime;
    private readonly string $url;
    private readonly ?WebSocketTrace $trace;

    /**
     * @param array{scheme: string, authority: string, path: string, query: ?string} $url
     */
    public function __construct(
        private readonly array $urlParts,
        private readonly array $options,
        private readonly FrameEncoder $encoder,
        private readonly Randomizer $randomizer,
        ?WebSocketTraceLog $traceLog = null,
    ) {
        $this->parser = new FrameParser();
        $this->url = ('https:' === $urlParts['scheme'] ? 'wss:' : 'ws:').$urlParts['authority'].$urlParts['path'].($urlParts['query'] ?? '');
        $this->startTime = microtime(true);
        $this->trace = $traceLog?->start($this->url);
    }

    public function __destruct()
    {
        $this->closeSocket();
    }

    /**
     * Performs the opening handshake unless already done.
     *
     * @throws TransportException
     */
    public function connect(): void
    {
        if ($this->error) {
            throw $this->error;
        }
        if (null !== $this->statusCode || $this->canceled) {
            return;
        }

        try {
            $this->handshake();
        } catch (\Throwable $e) {
            $this->closeSocket();
            $this->closed = true;
            $this->error = $e instanceof TransportException ? $e : new TransportException($e->getMessage(), 0, $e);
            $this->trace?->fail($this->error->getMessage());

            throw $this->error;
        }
    }

    public function getStatusCode(): int
    {
        $this->connect();

        return $this->statusCode;
    }

    public function getHeaders(bool $throw = true): array
    {
        $this->connect();
        $throw && $this->checkStatusCode();

        return $this->headers;
    }

    /**
     * Without a handshake error, blocks until the server closes the connection and returns all messages concatenated.
     * Use {@see WebSocketClient::stream()} to handle messages one by one.
     */
    public function getContent(bool $throw = true): string
    {
        $this->connect();
        $throw && $this->checkStatusCode();

        if (101 !== $this->statusCode) {
            return $this->body;
        }

        $content = '';
        $timeout = $this->timeout();
        while (true) {
            while (null !== $message = $this->nextMessage()) {
                $content .= $message;
            }
            if ($this->closed) {
                if ($this->streamError) {
                    throw $this->streamError;
                }

                return $content;
            }
            $read = [$this->socket];
            $write = $except = null;
            if (!stream_select($read, $write, $except, (int) $timeout, (int) (($timeout - (int) $timeout) * 1e6))) {
                throw new TransportException(sprintf('Idle timeout reached for "%s".', $this->url));
            }
            $this->readAvailable();
        }
    }

    public function toArray(bool $throw = true): array
    {
        $content = $this->getContent($throw);

        try {
            $content = json_decode($content, true, 512, \JSON_BIGINT_AS_STRING | \JSON_THROW_ON_ERROR);
        } catch (\JsonException $e) {
            throw new JsonException($e->getMessage().sprintf(' for "%s".', $this->url), $e->getCode());
        }

        if (!is_array($content)) {
            throw new JsonException(sprintf('JSON content was expected to decode to an array, "%s" returned for "%s".', get_debug_type($content), $this->url));
        }

        return $content;
    }

    public function cancel(): void
    {
        $this->canceled = true;
        $this->closed = true;
        $this->closeSocket();
    }

    public function getInfo(?string $type = null): mixed
    {
        $info = [
            'canceled' => $this->canceled,
            'error' => ($this->error ?? $this->streamError)?->getMessage(),
            'http_code' => $this->statusCode ?? 0,
            'http_method' => 'GET',
            'redirect_count' => 0,
            'redirect_url' => null,
            'response_headers' => $this->headerLines,
            'start_time' => $this->startTime,
            'url' => $this->url,
            'user_data' => $this->options['user_data'] ?? null,
        ];

        return null !== $type ? ($info[$type] ?? null) : $info;
    }

    public function send(string $payload, bool $binary = false): void
    {
        $this->sendFrame($binary ? Frame::BINARY : Frame::TEXT, $payload);
    }

    public function ping(string $payload = ''): void
    {
        $this->sendFrame(Frame::PING, $payload);
    }

    public function close(int $code = 1000, string $reason = ''): void
    {
        if (!$this->closed && $this->socket) {
            try {
                $this->sendFrame(Frame::CLOSE, pack('n', $code).$reason);
            } catch (TransportException) {
            }
        }
        $this->closed = true;
        $this->closeSocket();
    }

    public function isClosed(): bool
    {
        return $this->closed && !$this->messages;
    }

    /**
     * @internal
     */
    public function streamError(): ?TransportException
    {
        return $this->streamError;
    }

    /**
     * @return resource|null
     *
     * @internal
     */
    public function socket()
    {
        return $this->socket;
    }

    /**
     * @internal
     */
    public function consumedBytes(): int
    {
        return $this->consumedBytes;
    }

    /**
     * @internal
     */
    public function nextMessage(): ?string
    {
        $message = array_shift($this->messages);
        $this->consumedBytes += strlen($message ?? '');

        return $message;
    }

    /**
     * Reads whatever the socket has without blocking, answering ping and close frames.
     *
     * @internal
     */
    public function readAvailable(): void
    {
        while ($this->socket && !$this->closed) {
            $data = fread($this->socket, 8192);
            if (false === $data || ('' === $data && feof($this->socket))) {
                $this->closed = true;
                $this->closeSocket();

                return;
            }
            if ('' === $data) {
                return;
            }
            try {
                foreach ($this->parser->feed($data) as $frame) {
                    $this->handleFrame($frame);
                }
            } catch (ProtocolException $e) {
                $this->fail($e);

                return;
            }
        }
    }

    private function fail(ProtocolException $e): void
    {
        $this->close($e->closeCode);
        $this->streamError = new TransportException($e->getMessage(), 0, $e);
        $this->trace?->fail($e->getMessage());
    }

    private function handleFrame(Frame $frame): void
    {
        $this->trace?->frame('in', $frame->opcode, $frame->payload);

        switch ($frame->opcode) {
            case Frame::PING:
                $this->sendFrame(Frame::PONG, $frame->payload);
                return;
            case Frame::PONG:
                return;
            case Frame::CLOSE:
                $this->close();
                return;
        }

        if (Frame::CONTINUATION === $frame->opcode) {
            if (null === $this->fragmentOpcode) {
                throw new ProtocolException('Received a continuation frame without a message to continue.');
            }
        } elseif (null !== $this->fragmentOpcode) {
            throw new ProtocolException('Received a new data frame before the previous message was completed.');
        } else {
            $this->fragmentOpcode = $frame->opcode;
        }

        if (strlen($this->fragments) + strlen($frame->payload) > FrameParser::DEFAULT_MAX_PAYLOAD) {
            throw new ProtocolException('Received a message larger than the allowed limit.', ProtocolException::MESSAGE_TOO_BIG);
        }

        $this->fragments .= $frame->payload;
        if (!$frame->final) {
            return;
        }

        if (Frame::TEXT === $this->fragmentOpcode && !preg_match('//u', $this->fragments)) {
            throw new ProtocolException('Received a text message that is not valid UTF-8.', ProtocolException::INVALID_PAYLOAD);
        }

        $this->messages[] = $this->fragments;
        $this->fragments = '';
        $this->fragmentOpcode = null;
    }

    private function sendFrame(int $opcode, string $payload): void
    {
        $this->connect();
        if (101 !== $this->statusCode || $this->closed || !$this->socket) {
            throw new TransportException('The WebSocket connection is not open.');
        }

        $this->writeAll($this->encoder->encode($opcode, $payload));
        $this->trace?->frame('out', $opcode, $payload);
    }

    private function writeAll(string $data): void
    {
        while ('' !== $data) {
            $written = @fwrite($this->socket, $data);
            if (false === $written) {
                throw new TransportException('Failed to write to the WebSocket connection.');
            }
            $data = substr($data, $written);
            if ('' !== $data) {
                $read = $except = null;
                $write = [$this->socket];
                if (!stream_select($read, $write, $except, (int) $this->timeout())) {
                    throw new TransportException(sprintf('Idle timeout reached for "%s".', $this->url));
                }
            }
        }
    }

    private function handshake(): void
    {
        $tls = 'https:' === $this->urlParts['scheme'];
        $authority = parse_url($this->urlParts['authority'], \PHP_URL_HOST) ? parse_url($this->urlParts['authority']) : [];
        $host = $authority['host'];
        $port = $authority['port'] ?? ($tls ? 443 : 80);
        $timeout = $this->timeout();

        $socket = @stream_socket_client(
            sprintf('%s://%s:%d', $tls ? 'ssl' : 'tcp', $host, $port),
            $errorCode,
            $errorMessage,
            $this->options['max_connect_duration'] ?: $timeout,
            \STREAM_CLIENT_CONNECT,
            stream_context_create($this->contextOptions($host, $tls)),
        );
        if (!$socket) {
            throw new TransportException(sprintf('Could not connect to "%s": %s (%d).', $this->url, $errorMessage, $errorCode));
        }
        $this->socket = $socket;
        stream_set_timeout($socket, (int) $timeout, (int) (($timeout - (int) $timeout) * 1e6));

        $key = base64_encode($this->randomizer->getBytes(16));
        $this->writeAll($this->buildRequest($host, $port, $tls, $key));
        $this->readHandshakeResponse();
        $this->trace?->handshake($this->statusCode, $this->headerLines);

        if ($this->statusCode >= 300) {
            $this->readErrorBody();
            $this->closeSocket();
            $this->closed = true;

            return;
        }

        if (101 !== $this->statusCode) {
            throw new TransportException("Server replied with {$this->statusCode} when 101 was expected");
        }

        $received = $this->headers['sec-websocket-accept'][0] ?? '';
        if (base64_encode(sha1($key.self::HANDSHAKE_GUID, true)) !== $received) {
            throw new TransportException("Received Sec-WebSocket-Accept value '$received' did not match the expected one");
        }

        stream_set_blocking($socket, false);
    }

    private function buildRequest(string $host, int $port, bool $tls, string $key): string
    {
        $target = ($this->urlParts['path'] ?: '/').($this->urlParts['query'] ?? '');
        $defaultPort = $tls ? 443 : 80;
        $headers = $this->options['normalized_headers'];
        unset($headers['host'], $headers['connection'], $headers['upgrade'], $headers['sec-websocket-key'], $headers['sec-websocket-version']);

        $lines = [
            "GET $target HTTP/1.1",
            'Host: '.($port === $defaultPort ? $host : "$host:$port"),
            'Connection: Upgrade',
            'Upgrade: websocket',
            'Sec-WebSocket-Version: 13',
            "Sec-WebSocket-Key: $key",
        ];
        foreach ($headers as $values) {
            array_push($lines, ...$values);
        }

        return implode("\r\n", $lines)."\r\n\r\n";
    }

    private function readHandshakeResponse(): void
    {
        $statusLine = fgets($this->socket);
        if (false === $statusLine || !preg_match('#^HTTP/\d(?:\.\d)? (\d{3})#', $statusLine, $matches)) {
            throw new TransportException(sprintf('Invalid or missing handshake response from "%s".', $this->url));
        }
        $this->statusCode = (int) $matches[1];
        $this->headerLines[] = rtrim($statusLine);

        while (false !== ($line = fgets($this->socket)) && '' !== $line = rtrim($line)) {
            $this->headerLines[] = $line;
            if (str_contains($line, ':')) {
                [$name, $value] = explode(':', $line, 2);
                $this->headers[strtolower($name)][] = trim($value);
            }
        }
    }

    private function readErrorBody(): void
    {
        $length = isset($this->headers['content-length'][0]) ? (int) $this->headers['content-length'][0] : null;
        while ((null === $length || strlen($this->body) < $length) && !feof($this->socket)) {
            $chunk = fread($this->socket, null === $length ? 8192 : $length - strlen($this->body));
            if (false === $chunk || '' === $chunk) {
                break;
            }
            $this->body .= $chunk;
        }
    }

    private function checkStatusCode(): void
    {
        if (101 === $this->statusCode || $this->statusCode < 300) {
            return;
        }

        throw match (true) {
            $this->statusCode >= 500 => new ServerException($this),
            $this->statusCode >= 400 => new ClientException($this),
            default => new RedirectionException($this),
        };
    }

    private function contextOptions(string $host, bool $tls): array
    {
        $context = ['socket' => ['bindto' => $this->options['bindto']]];

        if ($tls) {
            $context['ssl'] = array_filter([
                'peer_name' => $host,
                'verify_peer' => $this->options['verify_peer'],
                'verify_peer_name' => $this->options['verify_host'],
                'cafile' => $this->options['cafile'],
                'capath' => $this->options['capath'],
                'local_cert' => $this->options['local_cert'],
                'local_pk' => $this->options['local_pk'],
                'passphrase' => $this->options['passphrase'],
                'ciphers' => $this->options['ciphers'],
                'peer_fingerprint' => $this->options['peer_fingerprint'],
            ], static fn ($value) => null !== $value) + ['verify_peer' => true];
        }

        return $context;
    }

    private function timeout(): float
    {
        return $this->options['timeout'] ?? (float) ini_get('default_socket_timeout');
    }

    private function closeSocket(): void
    {
        if (is_resource($this->socket)) {
            @fclose($this->socket);
        }
        $this->socket = null;
        $this->trace?->close();
    }
}
