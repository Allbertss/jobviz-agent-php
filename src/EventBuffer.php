<?php

declare(strict_types=1);

namespace Jobviz\Agent;

use Closure;

final class EventBuffer
{
    /** @var JobEvent[] */
    private array $buffer = [];

    private ?Closure $onFlush = null;

    public function __construct(
        private readonly int $batchSize = 100,
        private readonly int $maxBufferSize = 10_000,
    ) {}

    public function setFlushHandler(Closure $handler): void
    {
        $this->onFlush = $handler;
    }

    public function push(JobEvent $event): void
    {
        $this->buffer[] = $event;

        // Drop oldest events if buffer exceeds max size
        while (\count($this->buffer) > $this->maxBufferSize) {
            array_shift($this->buffer);
        }

        // Auto-flush when batch size reached
        if (\count($this->buffer) >= $this->batchSize) {
            $this->flush();
        }
    }

    /**
     * Flush all buffered events.
     */
    public function flush(): void
    {
        if ($this->buffer === [] || $this->onFlush === null) {
            return;
        }

        $events = $this->buffer;
        $this->buffer = [];

        ($this->onFlush)($events);
    }

    /**
     * Drain returns remaining events without flushing (for graceful shutdown).
     *
     * @return JobEvent[]
     */
    public function drain(): array
    {
        $events = $this->buffer;
        $this->buffer = [];

        return $events;
    }

    public function count(): int
    {
        return \count($this->buffer);
    }
}
