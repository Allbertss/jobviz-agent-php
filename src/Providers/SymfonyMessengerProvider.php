<?php

declare(strict_types=1);

namespace Jobviz\Agent\Providers;

use Closure;
use Jobviz\Agent\EventType;
use Jobviz\Agent\JobEvent;
use ReflectionClass;
use ReflectionProperty;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\Messenger\Event\SendMessageToTransportsEvent;
use Symfony\Component\Messenger\Event\WorkerMessageFailedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageHandledEvent;
use Symfony\Component\Messenger\Event\WorkerMessageReceivedEvent;
use Symfony\Component\Messenger\Event\WorkerMessageRetriedEvent;
use Symfony\Component\Messenger\Stamp\BusNameStamp;
use Symfony\Component\Messenger\Stamp\DelayStamp;
use Symfony\Component\Messenger\Stamp\RedeliveryStamp;
use Symfony\Component\Messenger\Stamp\SentToFailureTransportStamp;
use Symfony\Component\Messenger\Stamp\TransportMessageIdStamp;
use Throwable;

/**
 * Provider for Symfony Messenger.
 *
 * Implements EventSubscriberInterface so it can be registered directly with
 * Symfony's event dispatcher as a subscriber. Alternatively, call connect()
 * with a push callback for standalone usage.
 *
 * Supports all Symfony transports (AMQP, Redis, Doctrine, InMemory, etc.)
 * since it hooks into the Messenger component at the framework level.
 */
final class SymfonyMessengerProvider implements QueueProviderInterface, EventSubscriberInterface
{
    private ?Closure $push = null;

    /** @var array<string, int> Simple counter for generating unique IDs per message class */
    private array $idCounter = [];

    /**
     * @param string[]|null $transports Only monitor these transport names (null = all)
     * @param bool $captureInput Whether to capture message payload data
     * @param bool $captureStackTraces Whether to capture exception stack traces
     */
    public function __construct(
        private readonly ?array $transports = null,
        private readonly bool $captureInput = true,
        private readonly bool $captureStackTraces = true,
    ) {}

    // -------------------------------------------------------------------------
    // QueueProviderInterface
    // -------------------------------------------------------------------------

    public function connect(Closure $push): void
    {
        $this->push = $push;
    }

    public function disconnect(): void
    {
        $this->push = null;
        $this->idCounter = [];
    }

    // -------------------------------------------------------------------------
    // EventSubscriberInterface — allows auto-registration in Symfony DI
    // -------------------------------------------------------------------------

    public static function getSubscribedEvents(): array
    {
        $events = [
            WorkerMessageReceivedEvent::class => 'onWorkerMessageReceived',
            WorkerMessageHandledEvent::class => 'onWorkerMessageHandled',
            WorkerMessageFailedEvent::class => 'onWorkerMessageFailed',
        ];

        if (class_exists(SendMessageToTransportsEvent::class)) {
            $events[SendMessageToTransportsEvent::class] = 'onSendMessageToTransports';
        }

        if (class_exists(WorkerMessageRetriedEvent::class)) {
            $events[WorkerMessageRetriedEvent::class] = 'onWorkerMessageRetried';
        }

        return $events;
    }

    // -------------------------------------------------------------------------
    // Event handlers
    // -------------------------------------------------------------------------

    /**
     * Message dispatched to transport(s) — maps to "waiting".
     */
    public function onSendMessageToTransports(SendMessageToTransportsEvent $event): void
    {
        if ($this->push === null) {
            return;
        }

        $envelope = $event->getEnvelope();
        $message = $envelope->getMessage();
        $messageName = \get_class($message);
        $queue = $this->resolveTransportName($envelope);

        if (!$this->shouldMonitor($queue)) {
            return;
        }

        $data = [];
        if ($this->captureInput) {
            $data['input'] = $this->extractPayload($message);
        }

        $delayStamp = $envelope->last(DelayStamp::class);
        if ($delayStamp !== null) {
            $data['delay'] = $delayStamp->getDelay();
        }

        ($this->push)(new JobEvent(
            jobId: $this->resolveMessageId($envelope),
            jobName: $messageName,
            queue: $queue,
            event: $delayStamp !== null ? EventType::Delayed : EventType::Waiting,
            timestamp: $this->now(),
            data: $data !== [] ? $data : null,
            traceId: $this->extractTraceId($message),
        ));
    }

    /**
     * Worker received a message — maps to "active".
     */
    public function onWorkerMessageReceived(WorkerMessageReceivedEvent $event): void
    {
        if ($this->push === null) {
            return;
        }

        $envelope = $event->getEnvelope();
        $message = $envelope->getMessage();
        $queue = $event->getReceiverName();

        if (!$this->shouldMonitor($queue)) {
            return;
        }

        $data = [];
        if ($this->captureInput) {
            $data['input'] = $this->extractPayload($message);
        }

        $redelivery = $envelope->last(RedeliveryStamp::class);
        if ($redelivery !== null) {
            $data['attemptsMade'] = $redelivery->getRetryCount();
        }

        ($this->push)(new JobEvent(
            jobId: $this->resolveMessageId($envelope),
            jobName: \get_class($message),
            queue: $queue,
            event: EventType::Active,
            timestamp: $this->now(),
            data: $data !== [] ? $data : null,
            traceId: $this->extractTraceId($message),
        ));
    }

    /**
     * Worker handled message successfully — maps to "completed".
     */
    public function onWorkerMessageHandled(WorkerMessageHandledEvent $event): void
    {
        if ($this->push === null) {
            return;
        }

        $envelope = $event->getEnvelope();
        $queue = $event->getReceiverName();

        if (!$this->shouldMonitor($queue)) {
            return;
        }

        ($this->push)(new JobEvent(
            jobId: $this->resolveMessageId($envelope),
            jobName: \get_class($envelope->getMessage()),
            queue: $queue,
            event: EventType::Completed,
            timestamp: $this->now(),
        ));
    }

    /**
     * Worker failed to handle message — maps to "failed".
     */
    public function onWorkerMessageFailed(WorkerMessageFailedEvent $event): void
    {
        if ($this->push === null) {
            return;
        }

        $envelope = $event->getEnvelope();
        $queue = $event->getReceiverName();

        if (!$this->shouldMonitor($queue)) {
            return;
        }

        $throwable = $event->getThrowable();
        $data = [
            'failedReason' => $throwable->getMessage(),
        ];

        if ($this->captureStackTraces) {
            $data['stack'] = $throwable->getTraceAsString();
        }

        if ($event->willRetry()) {
            $data['failedReason'] = '[retrying] ' . $data['failedReason'];
        }

        // Check if sent to failure transport
        $failureStamp = $envelope->last(SentToFailureTransportStamp::class);
        if ($failureStamp !== null) {
            $data['failedReason'] = '[moved to failure transport] ' . $throwable->getMessage();
        }

        $redelivery = $envelope->last(RedeliveryStamp::class);
        if ($redelivery !== null) {
            $data['attemptsMade'] = $redelivery->getRetryCount();
        }

        ($this->push)(new JobEvent(
            jobId: $this->resolveMessageId($envelope),
            jobName: \get_class($envelope->getMessage()),
            queue: $queue,
            event: EventType::Failed,
            timestamp: $this->now(),
            data: $data,
        ));
    }

    /**
     * Worker retried a message — maps to "delayed" (queued for retry).
     */
    public function onWorkerMessageRetried(WorkerMessageRetriedEvent $event): void
    {
        if ($this->push === null) {
            return;
        }

        $envelope = $event->getEnvelope();
        $queue = $event->getReceiverName();

        if (!$this->shouldMonitor($queue)) {
            return;
        }

        $redelivery = $envelope->last(RedeliveryStamp::class);
        $data = [];
        if ($redelivery !== null) {
            $data['attemptsMade'] = $redelivery->getRetryCount();
        }

        ($this->push)(new JobEvent(
            jobId: $this->resolveMessageId($envelope),
            jobName: \get_class($envelope->getMessage()),
            queue: $queue,
            event: EventType::Delayed,
            timestamp: $this->now(),
            data: $data !== [] ? $data : null,
        ));
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function shouldMonitor(string $transport): bool
    {
        if ($this->transports === null) {
            return true;
        }

        return \in_array($transport, $this->transports, true);
    }

    private function resolveMessageId(object $envelope): string
    {
        // Try TransportMessageIdStamp first (most reliable)
        if (method_exists($envelope, 'last')) {
            $stamp = $envelope->last(TransportMessageIdStamp::class);
            if ($stamp !== null) {
                return (string) $stamp->getId();
            }
        }

        // Fallback: use spl_object_id + counter for uniqueness
        $message = method_exists($envelope, 'getMessage') ? $envelope->getMessage() : $envelope;
        $class = $message::class;

        if (!\array_key_exists($class, $this->idCounter)) {
            $this->idCounter[$class] = 0;
        }

        $this->idCounter[$class]++;

        return $class . '#' . $this->idCounter[$class];
    }

    private function resolveTransportName(object $envelope): string
    {
        if (method_exists($envelope, 'last')) {
            $busStamp = $envelope->last(BusNameStamp::class);
            if ($busStamp !== null) {
                return $busStamp->getBusName();
            }
        }

        return 'messenger';
    }

    /**
     * @return array<string, mixed>|null
     */
    private function extractPayload(object $message): ?array
    {
        try {
            $ref = new ReflectionClass($message);
            $props = [];

            foreach ($ref->getProperties(ReflectionProperty::IS_PUBLIC) as $prop) {
                if ($prop->isInitialized($message)) {
                    $value = $prop->getValue($message);
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

    private function extractTraceId(object $message): ?string
    {
        foreach (['traceId', 'correlationId'] as $prop) {
            if (property_exists($message, $prop)) {
                $value = $message->{$prop};
                if (\is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    private function now(): float
    {
        return round(microtime(true) * 1000);
    }
}
