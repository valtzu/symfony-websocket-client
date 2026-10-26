<?php

namespace Valtzu\WebSocketClient;

/**
 * @internal
 */
final class FrameParser
{
    public const DEFAULT_MAX_PAYLOAD = 16 * 1024 * 1024;

    private const DATA_OPCODES = [Frame::CONTINUATION, Frame::TEXT, Frame::BINARY];
    private const CONTROL_OPCODES = [Frame::CLOSE, Frame::PING, Frame::PONG];

    private string $buffer = '';

    public function __construct(
        private readonly int $maxPayload = self::DEFAULT_MAX_PAYLOAD,
    ) {
    }

    /**
     * @return list<Frame> the frames completed by the given bytes
     *
     * @throws ProtocolException
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

        $first = ord($this->buffer[0]);
        $final = ($first & 0x80) !== 0;
        $opcode = $first & 0x0f;

        if (($first & 0x70) !== 0) {
            throw new ProtocolException('Received a frame with reserved bits set.');
        }
        if (!in_array($opcode, self::DATA_OPCODES, true) && !in_array($opcode, self::CONTROL_OPCODES, true)) {
            throw new ProtocolException(sprintf('Received a frame with unknown opcode %d.', $opcode));
        }
        if (($this->buffer[1] & "\x80") !== "\x00") {
            throw new ProtocolException('Received a masked frame from the server.');
        }

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

        if ($length < 0) {
            throw new ProtocolException('Received a frame with an invalid length.');
        }
        if ($opcode >= Frame::CLOSE && (!$final || $length > 125)) {
            throw new ProtocolException('Received a fragmented or oversized control frame.');
        }
        if ($length > $this->maxPayload) {
            throw new ProtocolException(sprintf('Received a frame larger than the %d byte limit.', $this->maxPayload), ProtocolException::MESSAGE_TOO_BIG);
        }

        if ($available < $offset + $length) {
            return null;
        }

        $payload = substr($this->buffer, $offset, $length);
        $this->buffer = substr($this->buffer, $offset + $length);

        return new Frame($final, $opcode, $payload);
    }
}
