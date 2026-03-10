<?php

declare(strict_types=1);

namespace Jobviz\Agent\Providers;

use Closure;

/**
 * Composite provider that fans out to multiple providers simultaneously.
 *
 * All providers share the same push callback, so events from all sources
 * flow into a single agent buffer.
 *
 * Usage:
 *   $multi = new MultiProvider([
 *       new LaravelQueueProvider($events),
 *       new YourCustomProvider(),
 *   ]);
 */
final class MultiProvider implements QueueProviderInterface
{
    /**
     * @param QueueProviderInterface[] $providers
     */
    public function __construct(
        private readonly array $providers,
    ) {}

    public function connect(Closure $push): void
    {
        foreach ($this->providers as $provider) {
            $provider->connect($push);
        }
    }

    public function disconnect(): void
    {
        foreach ($this->providers as $provider) {
            $provider->disconnect();
        }
    }
}
