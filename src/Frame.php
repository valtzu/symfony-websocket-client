<?php

namespace Valtzu\WebSocketClient;

final readonly class Frame
{
    public const CONTINUATION = 0x0;
    public const TEXT = 0x1;
    public const BINARY = 0x2;
    public const CLOSE = 0x8;
    public const PING = 0x9;
    public const PONG = 0xA;

    public function __construct(
        public bool $final,
        public int $opcode,
        public string $payload,
    ) {
    }

    public function isControl(): bool
    {
        return $this->opcode >= self::CLOSE;
    }
}
