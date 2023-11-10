<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum StopwatchStatus: string
{
    case NotStarted = 'not_started';
    case Running = 'running';
    case Paused = 'paused';
    case Finished = 'finished';

    public function title(): string
    {
        return match($this) {
            self::NotStarted => 'Připravené ke startu',
            self::Running => 'Puštěné',
            self::Paused => 'Zastavené',
            self::Finished => 'Složené puzzle',
        };
    }
}
