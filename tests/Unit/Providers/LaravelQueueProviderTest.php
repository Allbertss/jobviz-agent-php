<?php

declare(strict_types=1);

namespace Jobviz\Agent\Tests\Unit\Providers;

use Jobviz\Agent\EventType;
use Jobviz\Agent\JobEvent;
use Jobviz\Agent\Providers\LaravelQueueProvider;
use Jobviz\Agent\Tests\Helpers\FakeEventDispatcher;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class LaravelQueueProviderTest extends TestCase
{
    private function makeQueueJob(
        string $jobId = 'job-1',
        string $queue = 'default',
        string $name = 'App\\Jobs\\TestJob',
        int $attempts = 1,
        ?string $rawBody = null,
        bool $isReleased = false,
    ): object {
        return new class ($jobId, $queue, $name, $attempts, $rawBody, $isReleased) {
            public function __construct(
                private string $jobId,
                private string $queue,
                private string $name,
                private int $attempts,
                private ?string $rawBody,
                private bool $released,
            ) {}

            public function getJobId(): string
            {
                return $this->jobId;
            }
            public function getQueue(): string
            {
                return $this->queue;
            }
            public function resolveName(): string
            {
                return $this->name;
            }
            public function getName(): string
            {
                return $this->name;
            }
            public function attempts(): int
            {
                return $this->attempts;
            }
            public function isReleased(): bool
            {
                return $this->released;
            }
            public function getRawBody(): string
            {
                return $this->rawBody ?? json_encode([
                    'displayName' => $this->name,
                    'job' => $this->name,
                    'data' => ['command' => 'O:serialized'],
                ]);
            }
        };
    }

    #[Test]
    public function registers_listeners_on_connect(): void
    {
        $dispatcher = new FakeEventDispatcher();
        $provider = new LaravelQueueProvider(events: $dispatcher);

        $provider->connect(fn(JobEvent $e) => null);

        $this->assertNotEmpty($dispatcher->listeners);
        $this->assertArrayHasKey(\Illuminate\Queue\Events\JobProcessing::class, $dispatcher->listeners);
        $this->assertArrayHasKey(\Illuminate\Queue\Events\JobProcessed::class, $dispatcher->listeners);
        $this->assertArrayHasKey(\Illuminate\Queue\Events\JobFailed::class, $dispatcher->listeners);
        $this->assertArrayHasKey(\Illuminate\Queue\Events\JobExceptionOccurred::class, $dispatcher->listeners);
    }

    #[Test]
    public function emits_active_event_on_job_processing(): void
    {
        $dispatcher = new FakeEventDispatcher();
        $provider = new LaravelQueueProvider(events: $dispatcher);

        $collected = [];
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $queueJob = $this->makeQueueJob(jobId: 'job-42', queue: 'emails', name: 'App\\Jobs\\SendEmail');
        $event = new \Illuminate\Queue\Events\JobProcessing('redis', $queueJob);
        $dispatcher->fire(\Illuminate\Queue\Events\JobProcessing::class, $event);

        $this->assertCount(1, $collected);
        $this->assertSame('job-42', $collected[0]->jobId);
        $this->assertSame('App\\Jobs\\SendEmail', $collected[0]->jobName);
        $this->assertSame('emails', $collected[0]->queue);
        $this->assertSame(EventType::Active, $collected[0]->event);
        $this->assertIsFloat($collected[0]->timestamp);
    }

    #[Test]
    public function emits_completed_event_on_job_processed(): void
    {
        $dispatcher = new FakeEventDispatcher();
        $provider = new LaravelQueueProvider(events: $dispatcher);

        $collected = [];
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $queueJob = $this->makeQueueJob(jobId: 'job-99', queue: 'default', attempts: 2);
        $event = new \Illuminate\Queue\Events\JobProcessed('redis', $queueJob);
        $dispatcher->fire(\Illuminate\Queue\Events\JobProcessed::class, $event);

        $this->assertCount(1, $collected);
        $this->assertSame(EventType::Completed, $collected[0]->event);
        $this->assertSame(2, $collected[0]->data['attemptsMade']);
    }

    #[Test]
    public function emits_failed_event_on_job_failed(): void
    {
        $dispatcher = new FakeEventDispatcher();
        $provider = new LaravelQueueProvider(events: $dispatcher);

        $collected = [];
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $queueJob = $this->makeQueueJob(jobId: 'job-fail', queue: 'high');
        $exception = new RuntimeException('Connection timed out');
        $event = new \Illuminate\Queue\Events\JobFailed('redis', $queueJob, $exception);
        $dispatcher->fire(\Illuminate\Queue\Events\JobFailed::class, $event);

        $this->assertCount(1, $collected);
        $this->assertSame(EventType::Failed, $collected[0]->event);
        $this->assertSame('Connection timed out', $collected[0]->data['failedReason']);
        $this->assertArrayHasKey('stack', $collected[0]->data);
        $this->assertArrayHasKey('attemptsMade', $collected[0]->data);
    }

    #[Test]
    public function failed_event_omits_stack_when_disabled(): void
    {
        $dispatcher = new FakeEventDispatcher();
        $provider = new LaravelQueueProvider(events: $dispatcher, captureStackTraces: false);

        $collected = [];
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $queueJob = $this->makeQueueJob();
        $exception = new RuntimeException('Error');
        $event = new \Illuminate\Queue\Events\JobFailed('redis', $queueJob, $exception);
        $dispatcher->fire(\Illuminate\Queue\Events\JobFailed::class, $event);

        $this->assertArrayNotHasKey('stack', $collected[0]->data);
    }

    #[Test]
    public function filters_by_queue_name(): void
    {
        $dispatcher = new FakeEventDispatcher();
        $provider = new LaravelQueueProvider(events: $dispatcher, queues: ['emails']);

        $collected = [];
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        // Event on 'default' queue — should be ignored
        $queueJob1 = $this->makeQueueJob(queue: 'default');
        $dispatcher->fire(
            \Illuminate\Queue\Events\JobProcessing::class,
            new \Illuminate\Queue\Events\JobProcessing('redis', $queueJob1),
        );

        // Event on 'emails' queue — should be captured
        $queueJob2 = $this->makeQueueJob(queue: 'emails');
        $dispatcher->fire(
            \Illuminate\Queue\Events\JobProcessing::class,
            new \Illuminate\Queue\Events\JobProcessing('redis', $queueJob2),
        );

        $this->assertCount(1, $collected);
        $this->assertSame('emails', $collected[0]->queue);
    }

    #[Test]
    public function monitors_all_queues_when_null(): void
    {
        $dispatcher = new FakeEventDispatcher();
        $provider = new LaravelQueueProvider(events: $dispatcher, queues: null);

        $collected = [];
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $dispatcher->fire(
            \Illuminate\Queue\Events\JobProcessing::class,
            new \Illuminate\Queue\Events\JobProcessing('redis', $this->makeQueueJob(queue: 'queue-a')),
        );
        $dispatcher->fire(
            \Illuminate\Queue\Events\JobProcessing::class,
            new \Illuminate\Queue\Events\JobProcessing('redis', $this->makeQueueJob(queue: 'queue-b')),
        );

        $this->assertCount(2, $collected);
    }

    #[Test]
    public function captures_input_when_enabled(): void
    {
        $dispatcher = new FakeEventDispatcher();
        $provider = new LaravelQueueProvider(events: $dispatcher, captureInput: true);

        $collected = [];
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $queueJob = $this->makeQueueJob(
            rawBody: json_encode([
                'displayName' => 'TestJob',
                'data' => ['email' => 'john@test.com', 'priority' => 'high'],
            ]),
        );

        $dispatcher->fire(
            \Illuminate\Queue\Events\JobProcessing::class,
            new \Illuminate\Queue\Events\JobProcessing('redis', $queueJob),
        );

        $this->assertNotNull($collected[0]->data['input']);
        $this->assertSame('john@test.com', $collected[0]->data['input']['email']);
    }

    #[Test]
    public function extracts_trace_id_from_queue_job_payload(): void
    {
        $dispatcher = new FakeEventDispatcher();
        $provider = new LaravelQueueProvider(events: $dispatcher);

        $collected = [];
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $queueJob = $this->makeQueueJob(
            rawBody: json_encode([
                'displayName' => 'TestJob',
                'traceId' => 'trace-abc-123',
                'data' => [],
            ]),
        );

        $dispatcher->fire(
            \Illuminate\Queue\Events\JobProcessing::class,
            new \Illuminate\Queue\Events\JobProcessing('redis', $queueJob),
        );

        $this->assertSame('trace-abc-123', $collected[0]->traceId);
    }

    #[Test]
    public function extracts_correlation_id_as_fallback_trace_id(): void
    {
        $dispatcher = new FakeEventDispatcher();
        $provider = new LaravelQueueProvider(events: $dispatcher);

        $collected = [];
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $queueJob = $this->makeQueueJob(
            rawBody: json_encode([
                'displayName' => 'TestJob',
                'correlationId' => 'corr-xyz',
                'data' => [],
            ]),
        );

        $dispatcher->fire(
            \Illuminate\Queue\Events\JobProcessing::class,
            new \Illuminate\Queue\Events\JobProcessing('redis', $queueJob),
        );

        $this->assertSame('corr-xyz', $collected[0]->traceId);
    }

    #[Test]
    public function handles_serialized_command_payload(): void
    {
        $dispatcher = new FakeEventDispatcher();
        $provider = new LaravelQueueProvider(events: $dispatcher, captureInput: true);

        $collected = [];
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $queueJob = $this->makeQueueJob(
            rawBody: json_encode([
                'displayName' => 'App\\Jobs\\SendEmail',
                'data' => ['command' => 'O:23:"App\\Jobs\\SendEmail":0:{}'],
            ]),
        );

        $dispatcher->fire(
            \Illuminate\Queue\Events\JobProcessing::class,
            new \Illuminate\Queue\Events\JobProcessing('redis', $queueJob),
        );

        $this->assertSame(true, $collected[0]->data['input']['_serialized']);
        $this->assertSame('App\\Jobs\\SendEmail', $collected[0]->data['input']['class']);
    }

    #[Test]
    public function exception_occurred_skips_released_jobs(): void
    {
        $dispatcher = new FakeEventDispatcher();
        $provider = new LaravelQueueProvider(events: $dispatcher);

        $collected = [];
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $releasedJob = $this->makeQueueJob(isReleased: true);
        $event = new \Illuminate\Queue\Events\JobExceptionOccurred(
            'redis',
            $releasedJob,
            new RuntimeException('Error'),
        );
        $dispatcher->fire(\Illuminate\Queue\Events\JobExceptionOccurred::class, $event);

        $this->assertCount(0, $collected);
    }

    #[Test]
    public function exception_occurred_emits_for_non_released_jobs(): void
    {
        $dispatcher = new FakeEventDispatcher();
        $provider = new LaravelQueueProvider(events: $dispatcher);

        $collected = [];
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $job = $this->makeQueueJob(isReleased: false);
        $event = new \Illuminate\Queue\Events\JobExceptionOccurred(
            'redis',
            $job,
            new RuntimeException('Fatal error'),
        );
        $dispatcher->fire(\Illuminate\Queue\Events\JobExceptionOccurred::class, $event);

        $this->assertCount(1, $collected);
        $this->assertSame(EventType::Failed, $collected[0]->event);
        $this->assertSame('Fatal error', $collected[0]->data['failedReason']);
    }

    #[Test]
    public function disconnect_clears_internal_listener_references(): void
    {
        $dispatcher = new FakeEventDispatcher();
        $provider = new LaravelQueueProvider(events: $dispatcher);

        $provider->connect(fn(JobEvent $e) => null);
        $provider->disconnect();

        // Can reconnect without errors
        $collected = [];
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $this->assertNotEmpty($dispatcher->listeners);
    }
}
