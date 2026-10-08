<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where a round stands on the rounds timeline (RoundsTimelineBuilder): over (start + time limit passed), running, the
 * first one not over (Next - a running round is not Next), or a later one.
 */
enum RoundStatus: string
{
    case Past = 'past';
    case Live = 'live';
    case Next = 'next';
    case Later = 'later';
}
