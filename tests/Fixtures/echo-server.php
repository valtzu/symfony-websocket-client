<?php

$server = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
fwrite(STDOUT, parse_url('tcp://'.stream_socket_get_name($server, false), PHP_URL_PORT)."\n");
fflush(STDOUT);

$mode = $argv[1] ?? 'echo';
$connection = stream_socket_accept($server, 10);

$request = '';
while (!str_ends_with($request, "\r\n\r\n")) {
    $request .= fgets($connection);
}

if ('forbidden' === $mode) {
    fwrite($connection, "HTTP/1.1 403 Forbidden\r\nContent-Length: 4\r\n\r\nnope");
    exit;
}

preg_match('/^Sec-WebSocket-Key: (.+)\r$/mi', $request, $matches);
$accept = 'badaccept' === $mode ? 'foo' : base64_encode(sha1($matches[1].'258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
fwrite($connection, "HTTP/1.1 101 Switching Protocols\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Accept: $accept\r\n\r\n");

$frame = static fn (int $opcode, string $payload) => chr(0x80 | $opcode).chr(strlen($payload)).$payload;

if ('greet' === $mode) {
    fwrite($connection, $frame(0x9, 'hi'));
    fwrite($connection, $frame(0x1, 'Hello'));
    fwrite($connection, chr(0x01).chr(2).'Wo'.chr(0x80).chr(3).'rld');
    fwrite($connection, $frame(0x8, pack('n', 1000)));
    fread($connection, 4096);
    exit;
}

while (!feof($connection)) {
    $header = fread($connection, 2);
    if (strlen($header) < 2) {
        break;
    }
    $opcode = ord($header[0]) & 0x0f;
    $length = ord($header[1]) & 0x7f;
    $key = fread($connection, 4);
    $payload = '';
    while (strlen($payload) < $length) {
        $payload .= fread($connection, $length - strlen($payload));
    }
    $payload ^= str_repeat($key, intdiv($length, 4) + 1);
    if (0x8 === $opcode) {
        fwrite($connection, $frame(0x8, $payload));
        break;
    }
    if (0x1 === $opcode || 0x2 === $opcode) {
        fwrite($connection, $frame($opcode, $payload));
    }
}
