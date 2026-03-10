<?php

declare(strict_types=1);

namespace Jobviz\Agent\Tests\Unit\Providers;

use Jobviz\Agent\EventType;
use Jobviz\Agent\JobEvent;
use Jobviz\Agent\Providers\MultiProvider;
use Jobviz\Agent\Tests\Helpers\FakeProvider;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class MultiProviderTest extends TestCase
{
    #[Test]
    public function connects_all_providers(): void
    {
        $p1 = new FakeProvider();
        $p2 = new FakeProvider();
        $p3 = new FakeProvider();

        $multi = new MultiProvider([$p1, $p2, $p3]);
        $multi->connect(fn(JobEvent $e) => null);

        $this->assertTrue($p1->connected);
        $this->assertTrue($p2->connected);
        $this->assertTrue($p3->connected);
    }

    #[Test]
    public function disconnects_all_providers(): void
    {
        $p1 = new FakeProvider();
        $p2 = new FakeProvider();

        $multi = new MultiProvider([$p1, $p2]);
        $multi->connect(fn(JobEvent $e) => null);
        $multi->disconnect();

        $this->assertTrue($p1->disconnected);
        $this->assertTrue($p2->disconnected);
    }

    #[Test]
    public function all_providers_share_same_push_callback(): void
    {
        $p1 = new FakeProvider();
        $p2 = new FakeProvider();

        $collected = [];
        $multi = new MultiProvider([$p1, $p2]);
        $multi->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e->jobId;
        });

        $p1->emit(new JobEvent(
            jobId: 'from-p1',
            jobName: 'Job1',
            queue: 'q1',
            event: EventType::Active,
            timestamp: 0.0,
        ));

        $p2->emit(new JobEvent(
            jobId: 'from-p2',
            jobName: 'Job2',
            queue: 'q2',
            event: EventType::Completed,
            timestamp: 0.0,
        ));

        $this->assertSame(['from-p1', 'from-p2'], $collected);
    }

    #[Test]
    public function works_with_empty_providers_array(): void
    {
        $multi = new MultiProvider([]);

        // Should not throw
        $multi->connect(fn(JobEvent $e) => null);
        $multi->disconnect();

        $this->assertTrue(true);
    }

    #[Test]
    public function works_with_single_provider(): void
    {
        $p1 = new FakeProvider();
        $collected = [];

        $multi = new MultiProvider([$p1]);
        $multi->connect(function (JobEvent $e) use (&$collected) {
            $collected[] = $e->jobId;
        });

        $p1->emit(new JobEvent(
            jobId: 'solo',
            jobName: 'SoloJob',
            queue: 'default',
            event: EventType::Waiting,
            timestamp: 0.0,
        ));

        $this->assertSame(['solo'], $collected);
    }
}
