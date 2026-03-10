<?php

declare(strict_types=1);

namespace Jobviz\Agent;

enum EventType: string
{
    case Waiting = 'waiting';
    case Active = 'active';
    case Completed = 'completed';
    case Failed = 'failed';
    case Delayed = 'delayed';
    case Stalled = 'stalled';
    case Progress = 'progress';
    case Deployment = 'deployment';
}
