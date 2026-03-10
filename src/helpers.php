<?php

declare(strict_types=1);

namespace Jobviz\Agent;

/**
 * Global singleton access for convenience.
 */
final class Jobviz
{
    private static ?JobvizAgent $instance = null;

    /**
     * Initialize and start the global Jobviz agent.
     */
    public static function init(Config $config): JobvizAgent
    {
        if (self::$instance !== null) {
            self::$instance->stop();
        }

        self::$instance = new JobvizAgent($config);
        self::$instance->start();

        return self::$instance;
    }

    /**
     * Stop the global Jobviz agent and flush remaining events.
     */
    public static function stop(): void
    {
        if (self::$instance === null) {
            return;
        }

        self::$instance->stop();
        self::$instance = null;
    }

    /**
     * Log a message for a specific job.
     *
     * No-op if the agent hasn't been initialized.
     *
     * @param array{id?: string, name?: string, queue?: string} $job
     * @param array<string, mixed>|null $meta
     */
    public static function log(array $job, string $message, ?array $meta = null): void
    {
        self::$instance?->log($job, $message, $meta);
    }

    /**
     * Track a deployment event.
     *
     * No-op if the agent hasn't been initialized.
     */
    public static function trackDeployment(
        string $version,
        ?string $commitHash = null,
        ?string $description = null,
    ): void {
        self::$instance?->trackDeployment($version, $commitHash, $description);
    }

    /**
     * Manually flush the event buffer.
     */
    public static function flush(): void
    {
        self::$instance?->flush();
    }

    /**
     * Get the current agent instance (or null if not initialized).
     */
    public static function instance(): ?JobvizAgent
    {
        return self::$instance;
    }
}
