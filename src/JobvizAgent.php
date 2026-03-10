<?php

declare(strict_types=1);

namespace Jobviz\Agent;

final class JobvizAgent
{
    private const VERSION = '0.1.0';

    private HttpTransport $transport;
    private EventBuffer $buffer;
    private Redactor $redactor;
    private bool $running = false;

    public function __construct(
        private readonly Config $config,
    ) {
        $this->transport = new HttpTransport(
            endpoint: $config->endpoint,
            apiKey: $config->apiKey,
            onError: $config->onError,
            agentVersion: self::VERSION,
        );

        $this->buffer = new EventBuffer(
            batchSize: $config->batchSize,
            maxBufferSize: $config->maxBufferSize,
        );

        $this->buffer->setFlushHandler(fn(array $events) => $this->transport->send($events));

        $this->redactor = new Redactor($config->redactKeys);
    }

    /**
     * Start listening for queue events and forwarding them to Jobviz.
     */
    public function start(): void
    {
        if ($this->running) {
            return;
        }

        $this->running = true;

        $this->config->provider->connect(function (JobEvent $event): void {
            $this->push($event);
        });
    }

    /**
     * Stop listening and flush remaining events.
     */
    public function stop(): void
    {
        if (!$this->running) {
            return;
        }

        $this->running = false;
        $this->config->provider->disconnect();

        // Drain remaining events
        $this->buffer->flush();
        $remaining = $this->buffer->drain();
        if ($remaining !== []) {
            $this->transport->send($remaining);
        }
    }

    /**
     * Log a message for a specific job (creates a progress event).
     *
     * @param array{id?: string, name?: string, queue?: string} $job
     * @param array<string, mixed>|null $meta
     */
    public function log(array $job, string $message, ?array $meta = null): void
    {
        if (!$this->running) {
            return;
        }

        $logData = ['message' => $message];
        if ($meta !== null) {
            $logData['meta'] = $meta;
        }

        $this->push(new JobEvent(
            jobId: $job['id'] ?? 'unknown',
            jobName: $job['name'] ?? 'unknown',
            queue: $job['queue'] ?? 'default',
            event: EventType::Progress,
            timestamp: round(microtime(true) * 1000),
            env: $this->config->env,
            data: ['log' => $logData],
        ));
    }

    /**
     * Track a deployment event.
     */
    public function trackDeployment(
        string $version,
        ?string $commitHash = null,
        ?string $description = null,
    ): void {
        $this->push(new JobEvent(
            jobId: bin2hex(random_bytes(16)),
            jobName: 'deployment',
            queue: 'system',
            event: EventType::Deployment,
            timestamp: round(microtime(true) * 1000),
            env: $this->config->env,
            data: array_filter([
                'version' => $version,
                'commitHash' => $commitHash,
                'description' => $description,
            ], fn($v) => $v !== null),
        ));
    }

    /**
     * Manually flush the event buffer.
     */
    public function flush(): void
    {
        $this->buffer->flush();
    }

    public function isRunning(): bool
    {
        return $this->running;
    }

    public function getBufferCount(): int
    {
        return $this->buffer->count();
    }

    // -------------------------------------------------------------------------
    // Internal
    // -------------------------------------------------------------------------

    private function push(JobEvent $event): void
    {
        // Apply environment tag if configured and not already set
        if ($this->config->env !== null && $event->env === null) {
            $event = new JobEvent(
                jobId: $event->jobId,
                jobName: $event->jobName,
                queue: $event->queue,
                event: $event->event,
                timestamp: $event->timestamp,
                env: $this->config->env,
                parentJobId: $event->parentJobId,
                parentQueue: $event->parentQueue,
                traceId: $event->traceId,
                data: $event->data !== null ? $this->redactor->redact($event->data) : null,
            );
        } elseif ($event->data !== null) {
            $event = new JobEvent(
                jobId: $event->jobId,
                jobName: $event->jobName,
                queue: $event->queue,
                event: $event->event,
                timestamp: $event->timestamp,
                env: $event->env,
                parentJobId: $event->parentJobId,
                parentQueue: $event->parentQueue,
                traceId: $event->traceId,
                data: $this->redactor->redact($event->data),
            );
        }

        $this->buffer->push($event);
    }
}
