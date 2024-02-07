<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum StopwatchStatus: string
{
    case NotStarted = 'not_started';
    case Running = 'running';
    case Paused = 'paused';
    case Finished = 'finished';
}
