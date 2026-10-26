<?php

namespace Valtzu\WebSocketClient;

final class FrameParser
{
    private string $buffer = '';

    /**
     * @return list<Frame> the frames completed by the given bytes
     */
    public function feed(string $data): array
    {
        $this->buffer .= $data;
        $frames = [];

        while (null !== $frame = $this->parseOne()) {
            $frames[] = $frame;
        }

        return $frames;
    }

    private function parseOne(): ?Frame
    {
        $available = strlen($this->buffer);
        if ($available < 2) {
            return null;
        }

        $masked = (ord($this->buffer[1]) & 0x80) !== 0;
        $length = ord($this->buffer[1]) & 0x7f;
        $offset = 2;

        if ($length === 0x7e || $length === 0x7f) {
            $extendedLength = $length === 0x7e ? 2 : 8;
            if ($available < $offset + $extendedLength) {
                return null;
            }
            $length = unpack($length === 0x7e ? 'n' : 'J', $this->buffer, $offset)[1];
            $offset += $extendedLength;
        }

        $maskingKey = '';
        if ($masked) {
            if ($available < $offset + 4) {
                return null;
            }
            $maskingKey = substr($this->buffer, $offset, 4);
            $offset += 4;
        }

        if ($available < $offset + $length) {
            return null;
        }

        $payload = substr($this->buffer, $offset, $length);
        if ($masked) {
            $payload ^= str_repeat($maskingKey, ($length >> 2) + 1);
        }

        $first = ord($this->buffer[0]);
        $this->buffer = substr($this->buffer, $offset + $length);

        return new Frame(($first & 0x80) !== 0, $first & 0x0f, $payload);
    }
}
