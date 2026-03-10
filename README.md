# jobviz-agent

Lightweight SDK for streaming job lifecycle events from your PHP application to [Jobviz](https://jobviz.dev) — real-time monitoring, alerting, and AI-powered debugging for background jobs.

Supports **Laravel Queue**, **Symfony Messenger**, and custom providers.

## Install

```bash
composer require jobviz/agent
```

## Quick Start

### Laravel (zero-config)

Add your API key to `.env`:

```dotenv
JOBVIZ_API_KEY=your-api-key
```

That's it. The service provider auto-discovers via Composer, hooks into Laravel's queue events, and streams them to Jobviz in batches.

Optionally publish the config file:

```bash
php artisan vendor:publish --provider="Jobviz\Agent\Laravel\JobvizServiceProvider"
```

### Symfony Messenger

```php
use Jobviz\Agent\Config;
use Jobviz\Agent\Jobviz;
use Jobviz\Agent\Providers\SymfonyMessengerProvider;

Jobviz::init(new Config(
    apiKey: $_ENV['JOBVIZ_API_KEY'],
    provider: new SymfonyMessengerProvider(
        dispatcher: $container->get('event_dispatcher'),
    ),
));
```

Register `SymfonyMessengerProvider` as a service to auto-wire it as an event subscriber.

### Multiple queue systems

```php
use Jobviz\Agent\Config;
use Jobviz\Agent\Jobviz;
use Jobviz\Agent\Providers\MultiProvider;
use Jobviz\Agent\Providers\LaravelQueueProvider;
use Jobviz\Agent\Providers\SymfonyMessengerProvider;

Jobviz::init(new Config(
    apiKey: $_ENV['JOBVIZ_API_KEY'],
    provider: new MultiProvider([
        new LaravelQueueProvider($app['events']),
        new SymfonyMessengerProvider($dispatcher),
    ]),
));
```

## In-Job Logging

Attach structured log entries to a running job for step-by-step visibility in the Jobviz timeline:

```php
use Jobviz\Agent\Jobviz;

// Inside your job's handle() method
Jobviz::log(
    ['id' => $this->job->getJobId(), 'name' => 'SendEmail', 'queue' => 'emails'],
    'Fetching template',
);

Jobviz::log(
    ['id' => $this->job->getJobId(), 'name' => 'SendEmail', 'queue' => 'emails'],
    'Sending email',
    ['recipients' => count($this->recipients)],
);
```

## Deployment Tracking

Correlate deployments with job failures in the Jobviz dashboard:

```php
use Jobviz\Agent\Jobviz;

Jobviz::trackDeployment(
    version: '1.4.2',
    commitHash: 'abc123f',
    description: 'Fix email retry logic',
);
```

## Configuration

| Option | Type | Default | Description |
|--------|------|---------|-------------|
| `apiKey` | `string` | *required* | Your Jobviz project API key |
| `provider` | `QueueProviderInterface` | *required* | Queue provider instance |
| `endpoint` | `string` | `https://app.jobviz.dev` | Jobviz API endpoint |
| `env` | `?string` | `null` | Environment tag (e.g. `production`, `staging`) |
| `batchSize` | `int` | `100` | Max events per HTTP batch |
| `flushInterval` | `float` | `3.0` | Flush interval in seconds |
| `maxBufferSize` | `int` | `10000` | Max events buffered in memory before dropping oldest |
| `captureInput` | `bool` | `true` | Capture job payload data |
| `captureStackTraces` | `bool` | `true` | Capture stack traces on failure |
| `redactKeys` | `bool\|string[]` | `true` | Redact sensitive keys from job data |
| `debug` | `bool` | `false` | Enable verbose logging |
| `onError` | `?Closure` | `null` | Called when a batch fails to send |

### Laravel config

When using Laravel, all options are configurable via `config/jobviz.php` and environment variables. See `JOBVIZ_API_KEY`, `JOBVIZ_ENDPOINT`, `JOBVIZ_ENV`, and `JOBVIZ_DEBUG`.

### Internal buffering

The event buffer holds up to `maxBufferSize` events (default 10 000) in memory. When the buffer is full, the **oldest events are dropped** to prevent unbounded memory growth.

HTTP transport retries failed requests with exponential backoff (up to 4 attempts) and respects `429 Retry-After` headers.

## Privacy & Data Sanitization

By default, the agent captures job input data and error stack traces — this powers Jobviz's debugging and AI root-cause analysis features.

If your jobs handle sensitive data, you have several levels of control:

```php
use Jobviz\Agent\Config;

// 1. Disable input capture entirely
new Config(apiKey: $key, provider: $provider, captureInput: false);

// 2. Disable stack trace capture
new Config(apiKey: $key, provider: $provider, captureStackTraces: false);

// 3. Redact built-in sensitive keys (password, secret, token, etc.)
new Config(apiKey: $key, provider: $provider, redactKeys: true);

// 4. Redact custom keys (merged with built-in set)
new Config(apiKey: $key, provider: $provider, redactKeys: ['ssn', 'dob', 'bankAccount']);

// 5. Disable redaction entirely
new Config(apiKey: $key, provider: $provider, redactKeys: false);
```

Built-in redacted keys: `password`, `secret`, `token`, `apikey`, `api_key`, `authorization`, `creditcard`, `credit_card`, `ssn`, `accesstoken`, `access_token`, `refreshtoken`, `refresh_token`.

Key matching is **case-insensitive** — `Authorization`, `AUTHORIZATION`, and `authorization` are all redacted.

See our [Privacy Policy](https://jobviz.dev/privacy) for full details on data handling.

## Multi-Instance Usage

The `Jobviz::init()` / `Jobviz::stop()` helpers manage a global singleton. For advanced use cases (tests, multi-tenant), instantiate `JobvizAgent` directly:

```php
use Jobviz\Agent\Config;
use Jobviz\Agent\JobvizAgent;

$agent = new JobvizAgent(new Config(
    apiKey: $_ENV['JOBVIZ_API_KEY'],
    provider: $provider,
));

$agent->start();

// Later...
$agent->stop();
```

## Custom Providers

Implement the `QueueProviderInterface` to monitor any queue system:

```php
use Closure;
use Jobviz\Agent\JobEvent;
use Jobviz\Agent\Providers\QueueProviderInterface;

class MyQueueProvider implements QueueProviderInterface
{
    public function connect(Closure $push): void
    {
        // Subscribe to your queue system and call $push(new JobEvent(...)) for each event
    }

    public function disconnect(): void
    {
        // Clean up connections
    }
}
```

## Graceful Shutdown

In Laravel, the service provider automatically flushes remaining events when the application terminates.

For standalone usage, call `stop()` to flush the buffer before your process exits:

```php
use Jobviz\Agent\Jobviz;

register_shutdown_function(fn() => Jobviz::stop());
```

> **Tip:** `Jobviz::stop()` disconnects providers and flushes the in-memory buffer — typically under 1 second.

## Requirements

- PHP >= 8.1
- Guzzle HTTP >= 7.0
- One of: Laravel 10+/11+ or Symfony 6+/7+ (or a custom provider)

## Documentation

Full documentation is available at [jobviz.dev/docs](https://jobviz.dev/docs).

- [Getting Started](https://jobviz.dev/docs/getting-started)
- [PHP SDK Reference](https://jobviz.dev/docs/php-sdk)
- [Custom Integration](https://jobviz.dev/docs/custom-integration)
- [Examples](https://jobviz.dev/docs/examples)

## License

[MIT](LICENSE)
