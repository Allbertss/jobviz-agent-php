<?php

declare(strict_types=1);

namespace Jobviz\Agent\Laravel;

use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Support\ServiceProvider;
use Jobviz\Agent\Config;
use Jobviz\Agent\JobvizAgent;
use Jobviz\Agent\Providers\LaravelQueueProvider;

/**
 * Laravel service provider for zero-config Jobviz integration.
 *
 * Publishes config/jobviz.php and auto-starts the agent when
 * JOBVIZ_API_KEY is set.
 */
class JobvizServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../../config/jobviz.php', 'jobviz');

        $this->app->singleton(LaravelQueueProvider::class, function ($app) {
            return new LaravelQueueProvider(
                events: $app->make(Dispatcher::class),
                queues: config('jobviz.queues'),
                captureInput: (bool) config('jobviz.capture_input', true),
                captureStackTraces: (bool) config('jobviz.capture_stack_traces', true),
            );
        });

        $this->app->singleton(JobvizAgent::class, function ($app) {
            /** @var string|null $apiKey */
            $apiKey = config('jobviz.api_key');

            if ($apiKey === null || $apiKey === '') {
                return null;
            }

            $provider = $app->make(LaravelQueueProvider::class);

            $envConfig = config('jobviz.env');
            $appEnv = app()->environment();
            $env = \is_string($envConfig) ? $envConfig : (\is_string($appEnv) ? $appEnv : null);

            return new JobvizAgent(new Config(
                apiKey: $apiKey,
                provider: $provider,
                endpoint: (string) config('jobviz.endpoint', Config::DEFAULT_ENDPOINT),
                batchSize: (int) config('jobviz.batch_size', Config::DEFAULT_BATCH_SIZE),
                flushInterval: (float) config('jobviz.flush_interval', Config::DEFAULT_FLUSH_INTERVAL),
                maxBufferSize: (int) config('jobviz.max_buffer_size', Config::DEFAULT_MAX_BUFFER_SIZE),
                env: $env,
                captureInput: (bool) config('jobviz.capture_input', true),
                captureStackTraces: (bool) config('jobviz.capture_stack_traces', true),
                redactKeys: config('jobviz.redact_keys', true),
                debug: (bool) config('jobviz.debug', false),
            ));
        });
    }

    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__ . '/../../config/jobviz.php' => config_path('jobviz.php'),
            ], 'jobviz-config');
        }

        // Auto-start the agent if API key is configured
        $agent = $this->app->make(JobvizAgent::class);
        if ($agent instanceof JobvizAgent) {
            $agent->start();

            // Flush on termination
            $this->app->terminating(function () use ($agent): void {
                $agent->stop();
            });
        }
    }
}
