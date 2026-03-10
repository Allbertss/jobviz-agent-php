<?php

declare(strict_types=1);

namespace Jobviz\Agent\Providers;

use Closure;
use Jobviz\Agent\JobEvent;

/**
 * Implement this interface to integrate any PHP queue system with Jobviz.
 *
 * The provider's job is to listen for queue events and normalize them into
 * JobEvent objects, then pass them to the push callback supplied by connect().
 *
 * Providers must not throw from connect() or disconnect() — handle errors
 * internally or pass them via the push callback as failed events.
 */
interface QueueProviderInterface
{
    /**
     * Start listening for queue events.
     *
     * @param Closure(JobEvent): void $push Callback to push normalized events to the agent buffer.
     */
    public function connect(Closure $push): void;

    /**
     * Stop listening and release all resources.
     */
    public function disconnect(): void;
}
