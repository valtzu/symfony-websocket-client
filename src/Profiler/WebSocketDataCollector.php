<?php

namespace Valtzu\WebSocketClient\Profiler;

use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\DataCollector\DataCollector;
use Symfony\Component\HttpKernel\DataCollector\LateDataCollectorInterface;

/**
 * @internal
 */
final class WebSocketDataCollector extends DataCollector implements LateDataCollectorInterface
{
    public function __construct(
        private readonly WebSocketTraceLog $traceLog,
    ) {
    }

    public function collect(Request $request, Response $response, ?\Throwable $exception = null): void
    {
    }

    public function lateCollect(): void
    {
        $this->data = ['connections' => array_map(static fn (WebSocketTrace $trace) => $trace->toArray(), $this->traceLog->all())];
    }

    public function getName(): string
    {
        return 'websocket';
    }

    /**
     * @return list<array>
     */
    public function getConnections(): array
    {
        return $this->data['connections'] ?? [];
    }

    public function getFrameCount(): int
    {
        return array_sum(array_map(static fn (array $connection) => count($connection['frames']), $this->getConnections()));
    }
}
