<?php

namespace Valtzu\WebSocketClient\Profiler;

use Symfony\Contracts\Service\ResetInterface;

/**
 * @internal
 */
final class WebSocketTraceLog implements ResetInterface
{
    /** @var list<WebSocketTrace> */
    private array $traces = [];

    public function start(string $url): WebSocketTrace
    {
        return $this->traces[] = new WebSocketTrace($url, microtime(true));
    }

    /**
     * @return list<WebSocketTrace>
     */
    public function all(): array
    {
        return $this->traces;
    }

    public function reset(): void
    {
        $this->traces = [];
    }
}
