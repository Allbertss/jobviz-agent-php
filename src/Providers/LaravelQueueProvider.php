<?php

declare(strict_types=1);

namespace Jobviz\Agent\Providers;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Jobviz\Agent\EventType;
use Jobviz\Agent\JobEvent;
use ReflectionClass;
use ReflectionProperty;
use Throwable;

/**
 * Provider for Laravel Queue (any driver: Redis, Database, SQS, Beanstalkd, etc.)
 *
 * Hooks into Laravel's native queue event system via the event dispatcher.
 * Works with all queue drivers since it listens at the framework level,
 * not the driver level.
 */
final class LaravelQueueProvider implements QueueProviderInterface
{
    /** @var array<string, Closure> Registered listener closures keyed by event class */
    private array $listeners = [];

    /**
     * @param Dispatcher $events Laravel's event dispatcher
     * @param string[]|null $queues Only monitor these queue names (null = all)
     * @param bool $captureInput Whether to capture job payload data
     * @param bool $captureStackTraces Whether to capture exception stack traces
     */
    public function __construct(
        private readonly Dispatcher $events,
        private readonly ?array $queues = null,
        private readonly bool $captureInput = true,
        private readonly bool $captureStackTraces = true,
    ) {}

    public function connect(Closure $push): void
    {
        // JobQueued — fired when a job is dispatched to a queue
        if (class_exists(JobQueued::class)) {
            $this->listen($push, JobQueued::class, function (JobQueued $event) use ($push): void {
                $queue = $this->resolveQueueName($event->queue ?? 'default', $event->job ?? null);

                if (!$this->shouldMonitor($queue)) {
                    return;
                }

                $jobId = $this->resolveJobId($event);
                $jobName = $this->resolveJobName(null, $event->job ?? null);
                $data = [];

                if ($this->captureInput && isset($event->job) && \is_object($event->job)) {
                    $data['input'] = $this->extractPayload($event->job);
                }

                $push(new JobEvent(
                    jobId: $jobId,
                    jobName: $jobName,
                    queue: $queue,
                    event: EventType::Waiting,
                    timestamp: $this->now(),
                    data: $data !== [] ? $data : null,
                    traceId: $this->extractTraceId($event->job ?? null),
                ));
            });
        }

        // JobProcessing — fired when a worker picks up a job
        $this->listen($push, JobProcessing::class, function (JobProcessing $event) use ($push): void {
            $queue = $this->resolveQueueName($event->job->getQueue() ?? 'default', $event->job);

            if (!$this->shouldMonitor($queue)) {
                return;
            }

            $data = [];
            if ($this->captureInput) {
                $data['input'] = $this->extractPayloadFromQueueJob($event->job);
            }
            $data['attemptsMade'] = $event->job->attempts();

            $push(new JobEvent(
                jobId: $event->job->getJobId(),
                jobName: $this->resolveJobNameFromQueueJob($event->job),
                queue: $queue,
                event: EventType::Active,
                timestamp: $this->now(),
                data: $data !== [] ? $data : null,
                traceId: $this->extractTraceIdFromQueueJob($event->job),
            ));
        });

        // JobProcessed — fired when a job completes successfully
        $this->listen($push, JobProcessed::class, function (JobProcessed $event) use ($push): void {
            $queue = $this->resolveQueueName($event->job->getQueue() ?? 'default', $event->job);

            if (!$this->shouldMonitor($queue)) {
                return;
            }

            $push(new JobEvent(
                jobId: $event->job->getJobId(),
                jobName: $this->resolveJobNameFromQueueJob($event->job),
                queue: $queue,
                event: EventType::Completed,
                timestamp: $this->now(),
                data: ['attemptsMade' => $event->job->attempts()],
            ));
        });

        // JobFailed — fired when a job fails permanently
        $this->listen($push, JobFailed::class, function (JobFailed $event) use ($push): void {
            $queue = $this->resolveQueueName($event->job->getQueue() ?? 'default', $event->job);

            if (!$this->shouldMonitor($queue)) {
                return;
            }

            $data = [
                'failedReason' => $event->exception->getMessage(),
                'attemptsMade' => $event->job->attempts(),
            ];

            if ($this->captureStackTraces) {
                $data['stack'] = $event->exception->getTraceAsString();
            }

            $push(new JobEvent(
                jobId: $event->job->getJobId(),
                jobName: $this->resolveJobNameFromQueueJob($event->job),
                queue: $queue,
                event: EventType::Failed,
                timestamp: $this->now(),
                data: $data,
            ));
        });

        // JobReleasedAfterException — fired when a job is released back (will retry)
        if (class_exists(JobReleasedAfterException::class)) {
            $this->listen($push, JobReleasedAfterException::class, function (JobReleasedAfterException $event) use ($push): void {
                $queue = $this->resolveQueueName($event->job->getQueue() ?? 'default', $event->job);

                if (!$this->shouldMonitor($queue)) {
                    return;
                }

                $data = [
                    'failedReason' => '[retrying] ' . ($event->exception?->getMessage() ?? 'Unknown'),
                    'attemptsMade' => $event->job->attempts(),
                ];

                if ($this->captureStackTraces && $event->exception !== null) {
                    $data['stack'] = $event->exception->getTraceAsString();
                }

                $push(new JobEvent(
                    jobId: $event->job->getJobId(),
                    jobName: $this->resolveJobNameFromQueueJob($event->job),
                    queue: $queue,
                    event: EventType::Failed,
                    timestamp: $this->now(),
                    data: $data,
                ));
            });
        }

        // JobExceptionOccurred — fired on any exception during processing
        $this->listen($push, JobExceptionOccurred::class, function (JobExceptionOccurred $event) use ($push): void {
            $queue = $this->resolveQueueName($event->job->getQueue() ?? 'default', $event->job);

            if (!$this->shouldMonitor($queue)) {
                return;
            }

            // Only emit if the job won't be released (to avoid duplicate with JobReleasedAfterException)
            if ($event->job->isReleased()) {
                return;
            }

            $data = [
                'failedReason' => $event->exception->getMessage(),
                'attemptsMade' => $event->job->attempts(),
            ];

            if ($this->captureStackTraces) {
                $data['stack'] = $event->exception->getTraceAsString();
            }

            $push(new JobEvent(
                jobId: $event->job->getJobId(),
                jobName: $this->resolveJobNameFromQueueJob($event->job),
                queue: $queue,
                event: EventType::Failed,
                timestamp: $this->now(),
                data: $data,
            ));
        });
    }

    public function disconnect(): void
    {
        $this->listeners = [];
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function listen(Closure $push, string $eventClass, Closure $handler): void
    {
        $this->listeners[$eventClass] = $handler;
        $this->events->listen($eventClass, $handler);
    }

    private function shouldMonitor(string $queue): bool
    {
        if ($this->queues === null) {
            return true;
        }

        return \in_array($queue, $this->queues, true);
    }

    private function resolveJobId(JobQueued $event): string
    {
        // Laravel 10.15+ exposes the job ID on JobQueued
        if (property_exists($event, 'id') && $event->id !== null) {
            return (string) $event->id;
        }

        // Fallback: use the payload class's unique ID or a UUID
        if (isset($event->job) && \is_object($event->job) && method_exists($event->job, 'getJobId')) {
            return (string) $event->job->getJobId();
        }

        return bin2hex(random_bytes(16));
    }

    private function resolveJobName(?object $queueJob, mixed $underlyingJob): string
    {
        if ($underlyingJob !== null) {
            if (\is_object($underlyingJob)) {
                return $underlyingJob::class;
            }
            if (\is_string($underlyingJob)) {
                return $underlyingJob;
            }
        }

        if ($queueJob !== null && method_exists($queueJob, 'resolveName')) {
            return $queueJob->resolveName();
        }

        return 'unknown';
    }

    private function resolveJobNameFromQueueJob(object $job): string
    {
        if (method_exists($job, 'resolveName')) {
            return $job->resolveName();
        }

        if (method_exists($job, 'getName')) {
            return $job->getName();
        }

        $payload = $this->decodeRawPayload($job);

        if (isset($payload['displayName']) && \is_string($payload['displayName'])) {
            return $payload['displayName'];
        }

        if (isset($payload['job']) && \is_string($payload['job'])) {
            return $payload['job'];
        }

        return $job::class;
    }

    private function resolveQueueName(string $queue, ?object $job): string
    {
        if ($job !== null && method_exists($job, 'getQueue')) {
            return $job->getQueue() ?? $queue;
        }

        return $queue;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractPayloadFromQueueJob(object $job): ?array
    {
        $payload = $this->decodeRawPayload($job);
        $data = $payload['data']['command'] ?? $payload['data'] ?? null;

        if ($data === null) {
            return null;
        }

        // For serialized commands, just capture the class name to avoid huge payloads
        if (\is_string($data) && str_starts_with($data, 'O:')) {
            $displayName = isset($payload['displayName']) && \is_string($payload['displayName'])
                ? $payload['displayName']
                : 'unknown';

            return ['_serialized' => true, 'class' => $displayName];
        }

        if (\is_array($data)) {
            return $data;
        }

        return null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractPayload(object $job): ?array
    {
        try {
            $ref = new ReflectionClass($job);
            $props = [];
            foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
                if ($prop->isInitialized($job)) {
                    $value = $prop->getValue($job);
                    if (\is_scalar($value) || \is_array($value) || $value === null) {
                        $props[$prop->getName()] = $value;
                    } else {
                        $props[$prop->getName()] = get_debug_type($value);
                    }
                }
            }

            return $props !== [] ? $props : null;
        } catch (Throwable) {
            return null;
        }
    }

    private function extractTraceId(mixed $job): ?string
    {
        if (!\is_object($job)) {
            return null;
        }

        foreach (['traceId', 'correlationId'] as $prop) {
            if (property_exists($job, $prop)) {
                $value = $job->{$prop};
                if (\is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function extractTraceIdFromQueueJob(object $job): ?string
    {
        $payload = $this->decodeRawPayload($job);

        if (isset($payload['traceId']) && \is_string($payload['traceId'])) {
            return $payload['traceId'];
        }

        if (isset($payload['correlationId']) && \is_string($payload['correlationId'])) {
            return $payload['correlationId'];
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    private function decodeRawPayload(object $job): array
    {
        if (!method_exists($job, 'getRawBody')) {
            return [];
        }

        try {
            $body = $job->getRawBody();

            return json_decode($body, true, 16) ?? [];
        } catch (Throwable) {
            return [];
        }
    }

    private function now(): float
    {
        return round(microtime(true) * 1000);
    }
}
