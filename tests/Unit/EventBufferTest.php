<?php

declare(strict_types=1);

namespace Jobviz\Agent\Tests\Unit;

use Jobviz\Agent\EventBuffer;
use Jobviz\Agent\EventType;
use Jobviz\Agent\JobEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EventBufferTest extends TestCase
{
    private function makeEvent(string $jobId = 'job-1'): JobEvent
    {
        return new JobEvent(
            jobId: $jobId,
            jobName: 'TestJob',
            queue: 'default',
            event: EventType::Active,
            timestamp: 1700000000000.0,
        );
    }

    #[Test]
    public function push_adds_event_to_buffer(): void
    {
        $buffer = new EventBuffer(batchSize: 100);
        $buffer->setFlushHandler(fn(array $events) => null);

        $buffer->push($this->makeEvent());

        $this->assertSame(1, $buffer->count());
    }

    #[Test]
    public function auto_flushes_when_batch_size_reached(): void
    {
        $flushed = [];
        $buffer = new EventBuffer(batchSize: 3);
        $buffer->setFlushHandler(function (array $events) use (&$flushed) {
            $flushed = $events;
        });

        $buffer->push($this->makeEvent('job-1'));
        $buffer->push($this->makeEvent('job-2'));
        $this->assertEmpty($flushed);

        $buffer->push($this->makeEvent('job-3'));
        $this->assertCount(3, $flushed);
        $this->assertSame(0, $buffer->count());
    }

    #[Test]
    public function manual_flush_sends_all_buffered_events(): void
    {
        $flushed = [];
        $buffer = new EventBuffer(batchSize: 100);
        $buffer->setFlushHandler(function (array $events) use (&$flushed) {
            $flushed = $events;
        });

        $buffer->push($this->makeEvent('job-1'));
        $buffer->push($this->makeEvent('job-2'));

        $buffer->flush();

        $this->assertCount(2, $flushed);
        $this->assertSame(0, $buffer->count());
    }

    #[Test]
    public function flush_is_noop_when_buffer_is_empty(): void
    {
        $flushCount = 0;
        $buffer = new EventBuffer(batchSize: 100);
        $buffer->setFlushHandler(function (array $events) use (&$flushCount) {
            $flushCount++;
        });

        $buffer->flush();

        $this->assertSame(0, $flushCount);
    }

    #[Test]
    public function flush_is_noop_when_no_handler_set(): void
    {
        $buffer = new EventBuffer(batchSize: 100);
        $buffer->push($this->makeEvent());

        // Should not throw
        $buffer->flush();

        $this->assertSame(1, $buffer->count());
    }

    #[Test]
    public function drops_oldest_events_when_max_buffer_size_exceeded(): void
    {
        $buffer = new EventBuffer(batchSize: 100, maxBufferSize: 3);
        $buffer->setFlushHandler(fn(array $events) => null);

        $buffer->push($this->makeEvent('job-1'));
        $buffer->push($this->makeEvent('job-2'));
        $buffer->push($this->makeEvent('job-3'));
        $buffer->push($this->makeEvent('job-4'));

        $this->assertSame(3, $buffer->count());

        $drained = $buffer->drain();
        $ids = array_map(fn(JobEvent $e) => $e->jobId, $drained);

        // Oldest (job-1) should be dropped
        $this->assertSame(['job-2', 'job-3', 'job-4'], $ids);
    }

    #[Test]
    public function drain_returns_all_events_and_empties_buffer(): void
    {
        $buffer = new EventBuffer(batchSize: 100);
        $buffer->setFlushHandler(fn(array $events) => null);

        $buffer->push($this->makeEvent('job-1'));
        $buffer->push($this->makeEvent('job-2'));

        $drained = $buffer->drain();

        $this->assertCount(2, $drained);
        $this->assertSame(0, $buffer->count());
    }

    #[Test]
    public function drain_returns_empty_array_when_no_events(): void
    {
        $buffer = new EventBuffer();
        $this->assertSame([], $buffer->drain());
    }

    #[Test]
    public function count_tracks_buffer_size_accurately(): void
    {
        $buffer = new EventBuffer(batchSize: 100);
        $buffer->setFlushHandler(fn(array $events) => null);

        $this->assertSame(0, $buffer->count());

        $buffer->push($this->makeEvent());
        $this->assertSame(1, $buffer->count());

        $buffer->push($this->makeEvent());
        $this->assertSame(2, $buffer->count());

        $buffer->flush();
        $this->assertSame(0, $buffer->count());
    }

    #[Test]
    public function multiple_flushes_accumulate_correctly(): void
    {
        $flushCounts = [];
        $buffer = new EventBuffer(batchSize: 2);
        $buffer->setFlushHandler(function (array $events) use (&$flushCounts) {
            $flushCounts[] = \count($events);
        });

        // First auto-flush at 2
        $buffer->push($this->makeEvent('j1'));
        $buffer->push($this->makeEvent('j2'));

        // Second auto-flush at 2
        $buffer->push($this->makeEvent('j3'));
        $buffer->push($this->makeEvent('j4'));

        // Manual flush of remaining 0
        $buffer->flush();

        $this->assertSame([2, 2], $flushCounts);
    }
}
