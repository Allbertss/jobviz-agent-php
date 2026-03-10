<?php

declare(strict_types=1);

namespace Jobviz\Agent\Tests\Unit;

use Jobviz\Agent\EventType;
use Jobviz\Agent\JobEvent;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use ReflectionClass;

final class JobEventTest extends TestCase
{
    #[Test]
    public function can_be_constructed_with_required_fields(): void
    {
        $event = new JobEvent(
            jobId: 'job-1',
            jobName: 'SendEmail',
            queue: 'default',
            event: EventType::Active,
            timestamp: 1700000000000.0,
        );

        $this->assertSame('job-1', $event->jobId);
        $this->assertSame('SendEmail', $event->jobName);
        $this->assertSame('default', $event->queue);
        $this->assertSame(EventType::Active, $event->event);
        $this->assertSame(1700000000000.0, $event->timestamp);
        $this->assertNull($event->env);
        $this->assertNull($event->parentJobId);
        $this->assertNull($event->parentQueue);
        $this->assertNull($event->traceId);
        $this->assertNull($event->data);
    }

    #[Test]
    public function can_be_constructed_with_all_fields(): void
    {
        $event = new JobEvent(
            jobId: 'job-1',
            jobName: 'SendEmail',
            queue: 'emails',
            event: EventType::Completed,
            timestamp: 1700000000000.0,
            env: 'production',
            parentJobId: 'parent-1',
            parentQueue: 'parent-queue',
            traceId: 'trace-abc',
            data: ['returnValue' => 'ok'],
        );

        $this->assertSame('production', $event->env);
        $this->assertSame('parent-1', $event->parentJobId);
        $this->assertSame('parent-queue', $event->parentQueue);
        $this->assertSame('trace-abc', $event->traceId);
        $this->assertSame(['returnValue' => 'ok'], $event->data);
    }

    #[Test]
    public function toArray_includes_only_required_fields_when_optionals_are_null(): void
    {
        $event = new JobEvent(
            jobId: 'job-1',
            jobName: 'SendEmail',
            queue: 'default',
            event: EventType::Waiting,
            timestamp: 1700000000000.0,
        );

        $array = $event->toArray();

        $this->assertSame([
            'jobId' => 'job-1',
            'jobName' => 'SendEmail',
            'queue' => 'default',
            'event' => 'waiting',
            'timestamp' => 1700000000000.0,
        ], $array);

        $this->assertArrayNotHasKey('env', $array);
        $this->assertArrayNotHasKey('parentJobId', $array);
        $this->assertArrayNotHasKey('parentQueue', $array);
        $this->assertArrayNotHasKey('traceId', $array);
        $this->assertArrayNotHasKey('data', $array);
    }

    #[Test]
    public function toArray_includes_all_fields_when_set(): void
    {
        $event = new JobEvent(
            jobId: 'job-2',
            jobName: 'ProcessOrder',
            queue: 'orders',
            event: EventType::Failed,
            timestamp: 1700000000000.0,
            env: 'staging',
            parentJobId: 'parent-1',
            parentQueue: 'workflows',
            traceId: 'trace-xyz',
            data: ['failedReason' => 'timeout'],
        );

        $array = $event->toArray();

        $this->assertSame('job-2', $array['jobId']);
        $this->assertSame('ProcessOrder', $array['jobName']);
        $this->assertSame('orders', $array['queue']);
        $this->assertSame('failed', $array['event']);
        $this->assertSame(1700000000000.0, $array['timestamp']);
        $this->assertSame('staging', $array['env']);
        $this->assertSame('parent-1', $array['parentJobId']);
        $this->assertSame('workflows', $array['parentQueue']);
        $this->assertSame('trace-xyz', $array['traceId']);
        $this->assertSame(['failedReason' => 'timeout'], $array['data']);
    }

    #[Test]
    public function toArray_uses_enum_string_value_for_event(): void
    {
        foreach (EventType::cases() as $type) {
            $event = new JobEvent(
                jobId: 'j',
                jobName: 'n',
                queue: 'q',
                event: $type,
                timestamp: 0.0,
            );

            $this->assertSame($type->value, $event->toArray()['event']);
        }
    }

    #[Test]
    public function properties_are_readonly(): void
    {
        $ref = new ReflectionClass(JobEvent::class);

        foreach ($ref->getProperties() as $prop) {
            $this->assertTrue($prop->isReadOnly(), "Property {$prop->getName()} should be readonly");
        }
    }
}
