<?php

namespace Valtzu\WebSocketClient\Tests;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Valtzu\WebSocketClient\Frame;
use Valtzu\WebSocketClient\FrameEncoder;
use Valtzu\WebSocketClient\FrameParser;

class FrameTest extends TestCase
{
    #[Test]
    public function roundTripAcrossLengthClasses()
    {
        foreach ([0, 5, 125, 126, 70000] as $length) {
            $payload = $length ? random_bytes($length) : '';
            $frames = (new FrameParser())->feed((new FrameEncoder())->encode(Frame::BINARY, $payload));

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
        $bytes = (new FrameEncoder())->encode(Frame::TEXT, 'Hello').(new FrameEncoder())->encode(Frame::PING, 'x');

        $frames = [];
        foreach (str_split($bytes) as $byte) {
            array_push($frames, ...$parser->feed($byte));
        }

        $this->assertSame(['Hello', 'x'], array_map(static fn (Frame $f) => $f->payload, $frames));
    }
}
