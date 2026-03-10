<?php

declare(strict_types=1);

namespace Jobviz\Agent\Tests\Unit;

use Jobviz\Agent\Config;
use Jobviz\Agent\Jobviz;
use Jobviz\Agent\JobvizAgent;
use Jobviz\Agent\Tests\Helpers\FakeProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class JobvizSingletonTest extends TestCase
{
    protected function tearDown(): void
    {
        // Always clean up the singleton
        Jobviz::stop();
    }

    private function makeConfig(?FakeProvider $provider = null): Config
    {
        return new Config(
            apiKey: 'test-api-key-long-enough',
            provider: $provider ?? new FakeProvider(),
            endpoint: 'https://localhost:9999',
        );
    }

    #[Test]
    public function init_returns_agent_instance(): void
    {
        $agent = Jobviz::init($this->makeConfig());

        $this->assertInstanceOf(JobvizAgent::class, $agent);
        $this->assertTrue($agent->isRunning());
    }

    #[Test]
    public function instance_returns_current_agent(): void
    {
        $this->assertNull(Jobviz::instance());

        $agent = Jobviz::init($this->makeConfig());

        $this->assertSame($agent, Jobviz::instance());
    }

    #[Test]
    public function stop_clears_instance(): void
    {
        Jobviz::init($this->makeConfig());
        Jobviz::stop();

        $this->assertNull(Jobviz::instance());
    }

    #[Test]
    public function stop_is_safe_when_not_initialized(): void
    {
        Jobviz::stop(); // Should not throw
        $this->assertNull(Jobviz::instance());
    }

    #[Test]
    public function reinit_stops_previous_agent(): void
    {
        $provider1 = new FakeProvider();
        $provider2 = new FakeProvider();

        Jobviz::init($this->makeConfig($provider1));
        $this->assertTrue($provider1->connected);

        Jobviz::init($this->makeConfig($provider2));

        $this->assertTrue($provider1->disconnected);
        $this->assertTrue($provider2->connected);
    }

    #[Test]
    public function log_is_noop_when_not_initialized(): void
    {
        // Should not throw
        Jobviz::log(['id' => 'job-1'], 'Test message');

        $this->assertNull(Jobviz::instance());
    }

    #[Test]
    public function log_delegates_to_agent(): void
    {
        Jobviz::init($this->makeConfig());

        Jobviz::log(
            ['id' => 'job-1', 'name' => 'TestJob'],
            'Processing',
            ['step' => 1],
        );

        $this->assertSame(1, Jobviz::instance()->getBufferCount());
    }

    #[Test]
    public function trackDeployment_is_noop_when_not_initialized(): void
    {
        // Should not throw
        Jobviz::trackDeployment('1.0.0');

        $this->assertNull(Jobviz::instance());
    }

    #[Test]
    public function trackDeployment_delegates_to_agent(): void
    {
        Jobviz::init($this->makeConfig());

        Jobviz::trackDeployment('2.0.0', 'abc123', 'Major release');

        $this->assertSame(1, Jobviz::instance()->getBufferCount());
    }

    #[Test]
    public function flush_is_noop_when_not_initialized(): void
    {
        // Should not throw
        Jobviz::flush();
        $this->assertNull(Jobviz::instance());
    }

    #[Test]
    public function flush_delegates_to_agent(): void
    {
        $provider = new FakeProvider();
        Jobviz::init($this->makeConfig($provider));

        Jobviz::log(['id' => 'j1'], 'msg');
        $this->assertSame(1, Jobviz::instance()->getBufferCount());

        Jobviz::flush();
        $this->assertSame(0, Jobviz::instance()->getBufferCount());
    }
}
