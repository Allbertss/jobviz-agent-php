<?php

declare(strict_types=1);

namespace Jobviz\Agent\Tests\Unit;

use Jobviz\Agent\EventType;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;

final class EventTypeTest extends TestCase
{
    #[Test]
    public function all_event_types_have_correct_string_values(): void
    {
        $this->assertSame('waiting', EventType::Waiting->value);
        $this->assertSame('active', EventType::Active->value);
        $this->assertSame('completed', EventType::Completed->value);
        $this->assertSame('failed', EventType::Failed->value);
        $this->assertSame('delayed', EventType::Delayed->value);
        $this->assertSame('stalled', EventType::Stalled->value);
        $this->assertSame('progress', EventType::Progress->value);
        $this->assertSame('deployment', EventType::Deployment->value);
    }

    #[Test]
    public function has_exactly_eight_cases(): void
    {
        $this->assertCount(8, EventType::cases());
    }

    #[Test]
    public function can_be_created_from_string(): void
    {
        $this->assertSame(EventType::Waiting, EventType::from('waiting'));
        $this->assertSame(EventType::Failed, EventType::from('failed'));
    }

    #[Test]
    public function tryFrom_returns_null_for_invalid_value(): void
    {
        $this->assertNull(EventType::tryFrom('invalid'));
    }
}
