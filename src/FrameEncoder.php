<?php

namespace Valtzu\WebSocketClient;

use Random\Randomizer;

/**
 * @internal
 */
final readonly class FrameEncoder
{
    public function __construct(
        private Randomizer $randomizer = new Randomizer(),
    ) {
    }

    public function encode(int $opcode, string $payload): string
    {
        $length = strlen($payload);
        $maskingKey = $this->randomizer->getBytes(4);

        return chr(0x80 | $opcode)
            . match (true) {
                $length < 0x7e => pack('C', 0x80 | $length),
                $length <= 0xffff => pack('Cn', 0xfe, $length),
                default => pack('CJ', 0xff, $length),
            }
            . $maskingKey
            . ($payload ^ str_repeat($maskingKey, ($length >> 2) + 1));
    }
}
