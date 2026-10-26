<?php

namespace Valtzu\WebSocketClient;

final class ProtocolException extends \RuntimeException
{
    public const PROTOCOL_ERROR = 1002;
    public const INVALID_PAYLOAD = 1007;
    public const MESSAGE_TOO_BIG = 1009;

    public function __construct(string $message, public readonly int $closeCode = self::PROTOCOL_ERROR)
    {
        parent::__construct($message);
    }
}
