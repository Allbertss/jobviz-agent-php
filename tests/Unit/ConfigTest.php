<?php

declare(strict_types=1);

namespace Jobviz\Agent\Tests\Unit;

use Closure;
use InvalidArgumentException;
use Jobviz\Agent\Config;
use Jobviz\Agent\Providers\QueueProviderInterface;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use Throwable;

final class ConfigTest extends TestCase
{
    private function makeProvider(): QueueProviderInterface
    {
        return new class implements QueueProviderInterface {
            public function connect(Closure $push): void {}
            public function disconnect(): void {}
        };
    }

    #[Test]
    public function can_be_created_with_required_fields(): void
    {
        $provider = $this->makeProvider();
        $config = new Config(apiKey: 'test-api-key-long-enough', provider: $provider);

        $this->assertSame('test-api-key-long-enough', $config->apiKey);
        $this->assertSame($provider, $config->provider);
        $this->assertSame(Config::DEFAULT_ENDPOINT, $config->endpoint);
        $this->assertSame(Config::DEFAULT_BATCH_SIZE, $config->batchSize);
        $this->assertSame(Config::DEFAULT_FLUSH_INTERVAL, $config->flushInterval);
        $this->assertSame(Config::DEFAULT_MAX_BUFFER_SIZE, $config->maxBufferSize);
        $this->assertNull($config->env);
        $this->assertTrue($config->captureInput);
        $this->assertTrue($config->captureStackTraces);
        $this->assertFalse($config->debug);
        $this->assertNull($config->onError);
    }

    #[Test]
    public function throws_on_short_api_key(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('API key must be at least 10 characters');

        new Config(apiKey: 'short', provider: $this->makeProvider());
    }

    #[Test]
    public function throws_on_empty_api_key(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Config(apiKey: '', provider: $this->makeProvider());
    }

    #[Test]
    public function accepts_exactly_10_char_api_key(): void
    {
        $config = new Config(apiKey: '1234567890', provider: $this->makeProvider());
        $this->assertSame('1234567890', $config->apiKey);
    }

    #[Test]
    public function redact_keys_defaults_to_standard_sensitive_keys(): void
    {
        $config = new Config(apiKey: 'test-api-key-long', provider: $this->makeProvider());

        $this->assertContains('password', $config->redactKeys);
        $this->assertContains('secret', $config->redactKeys);
        $this->assertContains('token', $config->redactKeys);
        $this->assertContains('apikey', $config->redactKeys);
        $this->assertContains('api_key', $config->redactKeys);
        $this->assertContains('authorization', $config->redactKeys);
        $this->assertContains('creditcard', $config->redactKeys);
        $this->assertContains('credit_card', $config->redactKeys);
        $this->assertContains('ssn', $config->redactKeys);
        $this->assertContains('accesstoken', $config->redactKeys);
        $this->assertContains('access_token', $config->redactKeys);
        $this->assertContains('refreshtoken', $config->redactKeys);
        $this->assertContains('refresh_token', $config->redactKeys);
    }

    #[Test]
    public function redact_keys_false_disables_redaction(): void
    {
        $config = new Config(
            apiKey: 'test-api-key-long',
            provider: $this->makeProvider(),
            redactKeys: false,
        );

        $this->assertSame([], $config->redactKeys);
    }

    #[Test]
    public function redact_keys_array_merges_with_defaults(): void
    {
        $config = new Config(
            apiKey: 'test-api-key-long',
            provider: $this->makeProvider(),
            redactKeys: ['custom_secret', 'my_key'],
        );

        $this->assertContains('password', $config->redactKeys);
        $this->assertContains('custom_secret', $config->redactKeys);
        $this->assertContains('my_key', $config->redactKeys);
    }

    #[Test]
    public function redact_keys_array_deduplicates(): void
    {
        $config = new Config(
            apiKey: 'test-api-key-long',
            provider: $this->makeProvider(),
            redactKeys: ['password', 'password', 'new_key'],
        );

        $counts = array_count_values($config->redactKeys);
        $this->assertSame(1, $counts['password']);
    }

    #[Test]
    public function custom_values_are_stored(): void
    {
        $onError = fn(Throwable $e, int $d) => null;
        $config = new Config(
            apiKey: 'test-api-key-long',
            provider: $this->makeProvider(),
            endpoint: 'https://custom.endpoint',
            batchSize: 50,
            flushInterval: 5.0,
            maxBufferSize: 5000,
            env: 'production',
            captureInput: false,
            captureStackTraces: false,
            debug: true,
            onError: $onError,
        );

        $this->assertSame('https://custom.endpoint', $config->endpoint);
        $this->assertSame(50, $config->batchSize);
        $this->assertSame(5.0, $config->flushInterval);
        $this->assertSame(5000, $config->maxBufferSize);
        $this->assertSame('production', $config->env);
        $this->assertFalse($config->captureInput);
        $this->assertFalse($config->captureStackTraces);
        $this->assertTrue($config->debug);
        $this->assertSame($onError, $config->onError);
    }
}
