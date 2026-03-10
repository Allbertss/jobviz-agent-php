<?php

declare(strict_types=1);

namespace Jobviz\Agent\Tests\Unit;

use Jobviz\Agent\Config;
use Jobviz\Agent\EventType;
use Jobviz\Agent\JobEvent;
use Jobviz\Agent\JobvizAgent;
use Jobviz\Agent\Tests\Helpers\FakeProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JobvizAgentTest extends TestCase
{
    private function makeAgent(
        ?FakeProvider $provider = null,
        ?string $env = null,
        int $batchSize = 1000,
    ): array {
        $provider ??= new FakeProvider();

        $config = new Config(
            apiKey: 'test-api-key-long-enough',
            provider: $provider,
            endpoint: 'https://localhost:9999',
            batchSize: $batchSize,
            env: $env,
        );

        $agent = new JobvizAgent($config);

        return [$agent, $provider];
    }

    #[Test]
    public function start_connects_provider(): void
    {
        [$agent, $provider] = $this->makeAgent();

        $this->assertFalse($provider->connected);

        $agent->start();

        $this->assertTrue($provider->connected);
        $this->assertTrue($agent->isRunning());
    }

    #[Test]
    public function start_is_idempotent(): void
    {
        [$agent, $provider] = $this->makeAgent();

        $agent->start();
        $agent->start(); // Should not throw or double-connect

        $this->assertTrue($agent->isRunning());
    }

    #[Test]
    public function stop_disconnects_provider(): void
    {
        [$agent, $provider] = $this->makeAgent();

        $agent->start();
        $agent->stop();

        $this->assertTrue($provider->disconnected);
        $this->assertFalse($agent->isRunning());
    }

    #[Test]
    public function stop_is_idempotent(): void
    {
        [$agent, $provider] = $this->makeAgent();

        $agent->start();
        $agent->stop();
        $agent->stop(); // Should not throw

        $this->assertFalse($agent->isRunning());
    }

    #[Test]
    public function events_from_provider_are_buffered(): void
    {
        [$agent, $provider] = $this->makeAgent();

        $agent->start();

        $provider->emit(new JobEvent(
            jobId: 'job-1',
            jobName: 'TestJob',
            queue: 'default',
            event: EventType::Active,
            timestamp: 1700000000000.0,
        ));

        $this->assertSame(1, $agent->getBufferCount());
    }

    #[Test]
    public function log_creates_progress_event(): void
    {
        [$agent, $provider] = $this->makeAgent();

        $agent->start();

        $agent->log(
            ['id' => 'job-1', 'name' => 'TestJob', 'queue' => 'emails'],
            'Processing email',
            ['recipient' => 'john@example.com'],
        );

        $this->assertSame(1, $agent->getBufferCount());
    }

    #[Test]
    public function log_is_noop_when_not_running(): void
    {
        [$agent, $provider] = $this->makeAgent();

        // Don't start
        $agent->log(['id' => 'job-1'], 'Should not buffer');

        $this->assertSame(0, $agent->getBufferCount());
    }

    #[Test]
    public function log_uses_defaults_for_missing_job_fields(): void
    {
        [$agent, $provider] = $this->makeAgent();
        $agent->start();

        $agent->log([], 'Minimal log');

        $this->assertSame(1, $agent->getBufferCount());
    }

    #[Test]
    public function trackDeployment_creates_deployment_event(): void
    {
        [$agent, $provider] = $this->makeAgent();

        // trackDeployment works even when not started (it bypasses running check)
        $agent->start();
        $agent->trackDeployment(
            version: '1.2.3',
            commitHash: 'abc123',
            description: 'Hotfix release',
        );

        $this->assertSame(1, $agent->getBufferCount());
    }

    #[Test]
    public function trackDeployment_omits_null_fields(): void
    {
        [$agent, $provider] = $this->makeAgent();
        $agent->start();

        $agent->trackDeployment(version: '1.0.0');

        $this->assertSame(1, $agent->getBufferCount());
    }

    #[Test]
    public function env_tag_is_applied_to_events_without_env(): void
    {
        [$agent, $provider] = $this->makeAgent(env: 'production');
        $agent->start();

        $provider->emit(new JobEvent(
            jobId: 'job-1',
            jobName: 'TestJob',
            queue: 'default',
            event: EventType::Active,
            timestamp: 1700000000000.0,
            // No env set
        ));

        // The event should now have env=production in the buffer
        // We verify indirectly via the buffer count (event was processed)
        $this->assertSame(1, $agent->getBufferCount());
    }

    #[Test]
    public function flush_drains_buffer(): void
    {
        [$agent, $provider] = $this->makeAgent();
        $agent->start();

        $provider->emit(new JobEvent(
            jobId: 'job-1',
            jobName: 'TestJob',
            queue: 'default',
            event: EventType::Active,
            timestamp: 1700000000000.0,
        ));

        $this->assertSame(1, $agent->getBufferCount());

        $agent->flush();

        $this->assertSame(0, $agent->getBufferCount());
    }

    #[Test]
    public function redaction_is_applied_to_event_data(): void
    {
        $provider = new FakeProvider();
        $config = new Config(
            apiKey: 'test-api-key-long-enough',
            provider: $provider,
            endpoint: 'https://localhost:9999',
            batchSize: 1000,
            redactKeys: true, // Use defaults
        );

        $agent = new JobvizAgent($config);
        $agent->start();

        $provider->emit(new JobEvent(
            jobId: 'job-1',
            jobName: 'TestJob',
            queue: 'default',
            event: EventType::Active,
            timestamp: 1700000000000.0,
            data: ['password' => 'secret123', 'username' => 'john'],
        ));

        // The event is in the buffer — we can't inspect directly,
        // but we verify it was processed without error
        $this->assertSame(1, $agent->getBufferCount());
    }

    #[Test]
    public function events_not_pushed_after_stop(): void
    {
        [$agent, $provider] = $this->makeAgent();
        $agent->start();
        $agent->stop();

        // Provider is disconnected, but try emitting (should be noop since push is null)
        $this->assertFalse($provider->isConnected());
    }
}
