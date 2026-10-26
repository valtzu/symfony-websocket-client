<?php

namespace Valtzu\WebSocketClient\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Valtzu\WebSocketClient\Frame;
use Valtzu\WebSocketClient\FrameEncoder;
use Valtzu\WebSocketClient\FrameParser;
use Valtzu\WebSocketClient\ProtocolException;

class FrameTest extends TestCase
{
    #[Test]
    public function encoderMasksPayload()
    {
        $bytes = (new FrameEncoder())->encode(Frame::TEXT, 'Hello');

        $this->assertSame(0x81, ord($bytes[0]));
        $this->assertSame(0x80 | 5, ord($bytes[1]));
        $key = substr($bytes, 2, 4);
        $this->assertSame('Hello', substr($bytes, 6) ^ str_repeat($key, 2));
    }

    #[Test]
    public function parsesAcrossLengthClasses()
    {
        foreach ([0, 5, 125, 126, 70000] as $length) {
            $payload = $length ? random_bytes($length) : '';
            $frames = (new FrameParser())->feed(self::serverFrame(Frame::BINARY, $payload));

            $this->assertCount(1, $frames);
            $this->assertSame(Frame::BINARY, $frames[0]->opcode);
            $this->assertTrue($frames[0]->final);
            $this->assertSame($payload, $frames[0]->payload);
        }
    }

    #[Test]
    public function parsesFramesSplitAcrossReads()
    {
        $parser = new FrameParser();
        $bytes = self::serverFrame(Frame::TEXT, 'Hello').self::serverFrame(Frame::PING, 'x');

        $frames = [];
        foreach (str_split($bytes) as $byte) {
            array_push($frames, ...$parser->feed($byte));
        }

        $this->assertSame(['Hello', 'x'], array_map(static fn (Frame $f) => $f->payload, $frames));
    }

    #[Test]
    #[DataProvider('invalidFrames')]
    public function rejectsInvalidFrames(string $bytes, int $closeCode)
    {
        try {
            (new FrameParser(1024))->feed($bytes);
            $this->fail('Expected ProtocolException');
        } catch (ProtocolException $e) {
            $this->assertSame($closeCode, $e->closeCode);
        }
    }

    public static function invalidFrames(): iterable
    {
        yield 'reserved bit' => ["\xc1\x00", 1002];
        yield 'unknown opcode' => ["\x83\x00", 1002];
        yield 'masked frame' => ["\x81\x80\x00\x00\x00\x00", 1002];
        yield 'negative 64-bit length' => ["\x82\x7f\xff\xff\xff\xff\xff\xff\xff\xff", 1002];
        yield 'fragmented control frame' => ["\x09\x00", 1002];
        yield 'oversized control frame' => ["\x89\x7e\x00\x7e", 1002];
        yield 'payload over limit' => ["\x82\x7e\x04\x01", 1009];
    }

    private static function serverFrame(int $opcode, string $payload): string
    {
        $length = strlen($payload);

        return chr(0x80 | $opcode).match (true) {
            $length < 0x7e => chr($length),
            $length <= 0xffff => pack('Cn', 0x7e, $length),
            default => pack('CJ', 0x7f, $length),
        }.$payload;
    }
}
