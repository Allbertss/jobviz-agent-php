<?php

declare(strict_types=1);

namespace Jobviz\Agent\Tests\Helpers;

use Illuminate\Contracts\Events\Dispatcher;

final class FakeEventDispatcher implements Dispatcher
{
    /** @var array<string, callable[]> */
    public array $listeners = [];

    public function listen($events, $listener = null): void
    {
        $events = \is_array($events) ? $events : [$events];
        foreach ($events as $event) {
            $this->listeners[$event] ??= [];
            $this->listeners[$event][] = $listener;
        }
    }

    public function hasListeners($eventName): bool
    {
        return !empty($this->listeners[$eventName]);
    }

    /**
     * Fire all listeners for the given event class.
     */
    public function fire(string $eventClass, object $event): void
    {
        foreach ($this->listeners[$eventClass] ?? [] as $listener) {
            $listener($event);
        }
    }

    // Unused interface methods
    public function subscribe($subscriber): void {}
    public function dispatch($event, $payload = [], $halt = false)
    {
        return null;
    }
    public function push($event, $payload = []): void {}
    public function flush($event): void {}
    public function forget($event): void {}
    public function forgetPushed(): void {}
    public function until($event, $payload = [])
    {
        return null;
    }
}
