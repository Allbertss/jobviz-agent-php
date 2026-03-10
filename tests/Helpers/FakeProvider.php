<?php

declare(strict_types=1);

namespace Jobviz\Agent\Tests\Helpers;

use Closure;
use Jobviz\Agent\JobEvent;
use Jobviz\Agent\Providers\QueueProviderInterface;

/**
 * Fake provider for testing that captures the push callback and
 * allows manual event injection.
 */
final class FakeProvider implements QueueProviderInterface
{
    private ?Closure $push = null;
    public bool $connected = false;
    public bool $disconnected = false;

    public function connect(Closure $push): void
    {
        $this->push = $push;
        $this->connected = true;
        $this->disconnected = false;
    }

    public function disconnect(): void
    {
        $this->push = null;
        $this->disconnected = true;
        $this->connected = false;
    }

    /**
     * Simulate a queue event being emitted.
     */
    public function emit(JobEvent $event): void
    {
        if ($this->push !== null) {
            ($this->push)($event);
        }
    }

    public function isConnected(): bool
    {
        return $this->push !== null;
    }
}
