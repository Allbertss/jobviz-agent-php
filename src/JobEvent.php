<?php

declare(strict_types=1);

namespace Jobviz\Agent;

final class JobEvent
{
    /**
     * @param array<string, mixed>|null $data
     */
    public function __construct(
        public readonly string $jobId,
        public readonly string $jobName,
        public readonly string $queue,
        public readonly EventType $event,
        public readonly float $timestamp,
        public readonly ?string $env = null,
        public readonly ?string $parentJobId = null,
        public readonly ?string $parentQueue = null,
        public readonly ?string $traceId = null,
        public readonly ?array $data = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $payload = [
            'jobId' => $this->jobId,
            'jobName' => $this->jobName,
            'queue' => $this->queue,
            'event' => $this->event->value,
            'timestamp' => $this->timestamp,
        ];

        if ($this->env !== null) {
            $payload['env'] = $this->env;
        }
        if ($this->parentJobId !== null) {
            $payload['parentJobId'] = $this->parentJobId;
        }
        if ($this->parentQueue !== null) {
            $payload['parentQueue'] = $this->parentQueue;
        }
        if ($this->traceId !== null) {
            $payload['traceId'] = $this->traceId;
        }
        if ($this->data !== null) {
            $payload['data'] = $this->data;
        }

        return $payload;
    }
}
