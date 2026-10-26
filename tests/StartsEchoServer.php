<?php

namespace Valtzu\WebSocketClient\Tests;

trait StartsEchoServer
{
    /** @var resource|null */
    private $process = null;

    private function startServer(string $mode): string
    {
        $this->process = proc_open(
            [PHP_BINARY, __DIR__.'/Fixtures/echo-server.php', $mode],
            [1 => ['pipe', 'w']],
            $pipes,
        );
        $port = (int) trim(fgets($pipes[1]));

        return "ws://127.0.0.1:$port/socket?a=1";
    }

    private function stopServer(): void
    {
        if ($this->process) {
            proc_terminate($this->process);
            proc_close($this->process);
            $this->process = null;
        }
    }
}
