<?php

namespace Valtzu\WebSocketClient\Profiler;

use Valtzu\WebSocketClient\Frame;

/**
 * @internal
 */
final class WebSocketTrace
{
    private const PREVIEW_LIMIT = 2048;

    private ?int $statusCode = null;
    /** @var list<string> */
    private array $headerLines = [];
    /** @var list<array{time: float, direction: string, opcode: int, size: int, preview: ?string}> */
    private array $frames = [];
    private ?string $error = null;
    private ?float $closedAt = null;

    public function __construct(
        private readonly string $url,
        private readonly float $startTime,
    ) {
    }

    /**
     * @param list<string> $headerLines
     */
    public function handshake(int $statusCode, array $headerLines): void
    {
        $this->statusCode = $statusCode;
        $this->headerLines = $headerLines;
    }

    public function frame(string $direction, int $opcode, string $payload): void
    {
        $this->frames[] = [
            'time' => microtime(true),
            'direction' => $direction,
            'opcode' => $opcode,
            'size' => strlen($payload),
            'preview' => self::preview($opcode, $payload),
        ];
    }

    public function fail(string $message): void
    {
        $this->error = $message;
        $this->close();
    }

    public function close(): void
    {
        $this->closedAt ??= microtime(true);
    }

    /**
     * @return array{url: string, start: float, duration: ?float, status: ?int, headers: list<string>, frames: list<array>, error: ?string}
     */
    public function toArray(): array
    {
        return [
            'url' => $this->url,
            'start' => $this->startTime,
            'duration' => null !== $this->closedAt ? $this->closedAt - $this->startTime : null,
            'status' => $this->statusCode,
            'headers' => $this->headerLines,
            'frames' => $this->frames,
            'error' => $this->error,
        ];
    }

    private static function preview(int $opcode, string $payload): ?string
    {
        if (Frame::CLOSE === $opcode && strlen($payload) >= 2) {
            return trim(unpack('n', $payload)[1].' '.substr($payload, 2));
        }
        if (!preg_match('//u', $payload)) {
            return null;
        }
        preg_match('/^.{0,'.self::PREVIEW_LIMIT.'}/su', $payload, $matches);

        return $matches[0];
    }
}
