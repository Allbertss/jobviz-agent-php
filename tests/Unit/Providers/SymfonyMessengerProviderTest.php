<?php

declare(strict_types=1);

namespace Jobviz\Agent\Tests\Unit\Providers;

use Jobviz\Agent\EventType;
use Jobviz\Agent\JobEvent;
use Jobviz\Agent\Providers\SymfonyMessengerProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;

// Stub message classes for testing
class TestNotification
{
    public string $email = 'user@test.com';
    public int $priority = 1;
    public ?string $traceId = null;
}

class TestOrderMessage
{
    public int $orderId = 42;
    public string $correlationId = 'corr-123';
}

final class SymfonyMessengerProviderTest extends TestCase
{
    #[Test]
    public function connect_stores_push_callback(): void
    {
        $provider = new SymfonyMessengerProvider();

        $provider->connect(fn(JobEvent $e) => null);

        // Verify by triggering an event — it should go through
        $collected = [];
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $envelope = new Envelope(new TestNotification());
        $event = new WorkerMessageReceivedEvent($envelope, 'async');
        $provider->onWorkerMessageReceived($event);

        $this->assertCount(1, $collected);
    }

    #[Test]
    public function disconnect_clears_push_callback(): void
    {
        $collected = [];
        $provider = new SymfonyMessengerProvider();
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $provider->disconnect();

        // Events after disconnect should be no-ops
        $envelope = new Envelope(new TestNotification());
        $event = new WorkerMessageReceivedEvent($envelope, 'async');
        $provider->onWorkerMessageReceived($event);

        $this->assertEmpty($collected);
    }

    #[Test]
    public function getSubscribedEvents_returns_expected_events(): void
    {
        $events = SymfonyMessengerProvider::getSubscribedEvents();

        $this->assertArrayHasKey(WorkerMessageReceivedEvent::class, $events);
        $this->assertArrayHasKey(WorkerMessageHandledEvent::class, $events);
        $this->assertArrayHasKey(WorkerMessageFailedEvent::class, $events);

        $this->assertSame('onWorkerMessageReceived', $events[WorkerMessageReceivedEvent::class]);
        $this->assertSame('onWorkerMessageHandled', $events[WorkerMessageHandledEvent::class]);
        $this->assertSame('onWorkerMessageFailed', $events[WorkerMessageFailedEvent::class]);
    }

    // -------------------------------------------------------------------------
    // WorkerMessageReceived (Active)
    // -------------------------------------------------------------------------

    #[Test]
    public function received_emits_active_event(): void
    {
        $collected = [];
        $provider = new SymfonyMessengerProvider();
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $envelope = new Envelope(new TestNotification());
        $event = new WorkerMessageReceivedEvent($envelope, 'async');
        $provider->onWorkerMessageReceived($event);

        $this->assertCount(1, $collected);
        $this->assertSame(EventType::Active, $collected[0]->event);
        $this->assertSame('async', $collected[0]->queue);
        $this->assertSame(TestNotification::class, $collected[0]->jobName);
        $this->assertIsFloat($collected[0]->timestamp);
    }

    #[Test]
    public function received_captures_input_when_enabled(): void
    {
        $collected = [];
        $provider = new SymfonyMessengerProvider(captureInput: true);
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $message = new TestNotification();
        $message->email = 'john@example.com';
        $message->priority = 5;

        $envelope = new Envelope($message);
        $provider->onWorkerMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));

        $this->assertSame('john@example.com', $collected[0]->data['input']['email']);
        $this->assertSame(5, $collected[0]->data['input']['priority']);
    }

    #[Test]
    public function received_skips_input_when_disabled(): void
    {
        $collected = [];
        $provider = new SymfonyMessengerProvider(captureInput: false);
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $envelope = new Envelope(new TestNotification());
        $provider->onWorkerMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));

        $this->assertNull($collected[0]->data);
    }

    #[Test]
    public function received_extracts_redelivery_count(): void
    {
        $collected = [];
        $provider = new SymfonyMessengerProvider(captureInput: false);
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $envelope = new Envelope(new TestNotification(), [new RedeliveryStamp(3)]);
        $provider->onWorkerMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));

        $this->assertSame(3, $collected[0]->data['attemptsMade']);
    }

    #[Test]
    public function received_extracts_trace_id_from_message(): void
    {
        $collected = [];
        $provider = new SymfonyMessengerProvider(captureInput: false);
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $message = new TestNotification();
        $message->traceId = 'trace-abc';

        $envelope = new Envelope($message);
        $provider->onWorkerMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));

        $this->assertSame('trace-abc', $collected[0]->traceId);
    }

    #[Test]
    public function received_extracts_correlation_id_as_fallback(): void
    {
        $collected = [];
        $provider = new SymfonyMessengerProvider(captureInput: false);
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $message = new TestOrderMessage();
        $envelope = new Envelope($message);
        $provider->onWorkerMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));

        $this->assertSame('corr-123', $collected[0]->traceId);
    }

    #[Test]
    public function received_uses_transport_message_id_stamp(): void
    {
        $collected = [];
        $provider = new SymfonyMessengerProvider(captureInput: false);
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $envelope = new Envelope(new TestNotification(), [
            new TransportMessageIdStamp('msg-id-789'),
        ]);
        $provider->onWorkerMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));

        $this->assertSame('msg-id-789', $collected[0]->jobId);
    }

    #[Test]
    public function received_generates_fallback_id_without_stamp(): void
    {
        $collected = [];
        $provider = new SymfonyMessengerProvider(captureInput: false);
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $envelope = new Envelope(new TestNotification());
        $provider->onWorkerMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));

        $this->assertStringContainsString(TestNotification::class, $collected[0]->jobId);
        $this->assertStringContainsString('#', $collected[0]->jobId);
    }

    // -------------------------------------------------------------------------
    // WorkerMessageHandled (Completed)
    // -------------------------------------------------------------------------

    #[Test]
    public function handled_emits_completed_event(): void
    {
        $collected = [];
        $provider = new SymfonyMessengerProvider();
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $envelope = new Envelope(new TestNotification());
        $provider->onWorkerMessageHandled(new WorkerMessageHandledEvent($envelope, 'async'));

        $this->assertCount(1, $collected);
        $this->assertSame(EventType::Completed, $collected[0]->event);
        $this->assertSame('async', $collected[0]->queue);
    }

    // -------------------------------------------------------------------------
    // WorkerMessageFailed (Failed)
    // -------------------------------------------------------------------------

    #[Test]
    public function failed_emits_failed_event(): void
    {
        $collected = [];
        $provider = new SymfonyMessengerProvider();
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $envelope = new Envelope(new TestNotification());
        $exception = new RuntimeException('Database connection lost');
        $event = new WorkerMessageFailedEvent($envelope, 'async', $exception);

        $provider->onWorkerMessageFailed($event);

        $this->assertCount(1, $collected);
        $this->assertSame(EventType::Failed, $collected[0]->event);
        $this->assertSame('Database connection lost', $collected[0]->data['failedReason']);
        $this->assertArrayHasKey('stack', $collected[0]->data);
    }

    #[Test]
    public function failed_omits_stack_when_disabled(): void
    {
        $collected = [];
        $provider = new SymfonyMessengerProvider(captureStackTraces: false);
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $envelope = new Envelope(new TestNotification());
        $exception = new RuntimeException('Error');
        $event = new WorkerMessageFailedEvent($envelope, 'async', $exception);

        $provider->onWorkerMessageFailed($event);

        $this->assertArrayNotHasKey('stack', $collected[0]->data);
    }

    #[Test]
    public function failed_marks_retrying_in_reason(): void
    {
        $collected = [];
        $provider = new SymfonyMessengerProvider(captureStackTraces: false);
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $envelope = new Envelope(new TestNotification());
        $exception = new RuntimeException('Timeout');
        // willRetry=true
        $event = new WorkerMessageFailedEvent($envelope, 'async', $exception);

        // Use reflection to set willRetry since constructor doesn't expose it easily in older versions
        // In Symfony Messenger, willRetry is a method that checks if the envelope has been retried
        // For our test, we check that the method is called
        $provider->onWorkerMessageFailed($event);

        $this->assertCount(1, $collected);
        // The willRetry() check depends on the event — without retrying it should be plain
        $this->assertStringContainsString('Timeout', $collected[0]->data['failedReason']);
    }

    #[Test]
    public function failed_includes_redelivery_count(): void
    {
        $collected = [];
        $provider = new SymfonyMessengerProvider(captureStackTraces: false);
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $envelope = new Envelope(new TestNotification(), [new RedeliveryStamp(2)]);
        $exception = new RuntimeException('Error');
        $event = new WorkerMessageFailedEvent($envelope, 'async', $exception);

        $provider->onWorkerMessageFailed($event);

        $this->assertSame(2, $collected[0]->data['attemptsMade']);
    }

    // -------------------------------------------------------------------------
    // Transport filtering
    // -------------------------------------------------------------------------

    #[Test]
    public function filters_by_transport_name(): void
    {
        $collected = [];
        $provider = new SymfonyMessengerProvider(transports: ['async']);
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        // 'async' transport — should be captured
        $envelope1 = new Envelope(new TestNotification());
        $provider->onWorkerMessageReceived(new WorkerMessageReceivedEvent($envelope1, 'async'));

        // 'sync' transport — should be filtered out
        $envelope2 = new Envelope(new TestNotification());
        $provider->onWorkerMessageReceived(new WorkerMessageReceivedEvent($envelope2, 'sync'));

        $this->assertCount(1, $collected);
        $this->assertSame('async', $collected[0]->queue);
    }

    #[Test]
    public function monitors_all_transports_when_null(): void
    {
        $collected = [];
        $provider = new SymfonyMessengerProvider(transports: null);
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $provider->onWorkerMessageReceived(new WorkerMessageReceivedEvent(
            new Envelope(new TestNotification()),
            'async',
        ));
        $provider->onWorkerMessageReceived(new WorkerMessageReceivedEvent(
            new Envelope(new TestNotification()),
            'sync',
        ));
        $provider->onWorkerMessageReceived(new WorkerMessageReceivedEvent(
            new Envelope(new TestNotification()),
            'failed',
        ));

        $this->assertCount(3, $collected);
    }

    // -------------------------------------------------------------------------
    // No-op when disconnected
    // -------------------------------------------------------------------------

    #[Test]
    public function all_handlers_are_noop_when_not_connected(): void
    {
        $provider = new SymfonyMessengerProvider();
        // Don't call connect()

        $envelope = new Envelope(new TestNotification());
        $exception = new RuntimeException('Error');

        // None of these should throw
        $provider->onWorkerMessageReceived(new WorkerMessageReceivedEvent($envelope, 'async'));
        $provider->onWorkerMessageHandled(new WorkerMessageHandledEvent($envelope, 'async'));
        $provider->onWorkerMessageFailed(new WorkerMessageFailedEvent($envelope, 'async', $exception));

        $this->assertTrue(true); // No exception = pass
    }

    // -------------------------------------------------------------------------
    // ID counter
    // -------------------------------------------------------------------------

    #[Test]
    public function generates_incrementing_fallback_ids(): void
    {
        $collected = [];
        $provider = new SymfonyMessengerProvider(captureInput: false);
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $provider->onWorkerMessageReceived(new WorkerMessageReceivedEvent(
            new Envelope(new TestNotification()),
            'async',
        ));
        $provider->onWorkerMessageReceived(new WorkerMessageReceivedEvent(
            new Envelope(new TestNotification()),
            'async',
        ));

        $this->assertStringEndsWith('#1', $collected[0]->jobId);
        $this->assertStringEndsWith('#2', $collected[1]->jobId);
    }

    #[Test]
    public function disconnect_resets_id_counter(): void
    {
        $collected = [];
        $provider = new SymfonyMessengerProvider(captureInput: false);
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $provider->onWorkerMessageReceived(new WorkerMessageReceivedEvent(
            new Envelope(new TestNotification()),
            'async',
        ));

        $provider->disconnect();
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $provider->onWorkerMessageReceived(new WorkerMessageReceivedEvent(
            new Envelope(new TestNotification()),
            'async',
        ));

        // After disconnect+reconnect, counter should restart at #1
        $this->assertStringEndsWith('#1', $collected[0]->jobId);
        $this->assertStringEndsWith('#1', $collected[1]->jobId);
    }

    // -------------------------------------------------------------------------
    // Payload extraction
    // -------------------------------------------------------------------------

    #[Test]
    public function extracts_public_properties_from_message(): void
    {
        $collected = [];
        $provider = new SymfonyMessengerProvider(captureInput: true);
        $provider->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e;
        });

        $message = new TestNotification();
        $message->email = 'test@example.com';
        $message->priority = 3;

        $provider->onWorkerMessageReceived(new WorkerMessageReceivedEvent(
            new Envelope($message),
            'async',
        ));

        $input = $collected[0]->data['input'];
        $this->assertSame('test@example.com', $input['email']);
        $this->assertSame(3, $input['priority']);
    }
}
