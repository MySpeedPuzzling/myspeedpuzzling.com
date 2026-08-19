<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What a signed-in viewer sees next to every item of a collection page
 * (system or custom, own or somebody else's public one): nothing, their own
 * times on each puzzle, or their times plus a time prediction (members who
 * have not opted out of predictions). Persisted per player, applied to every
 * collection page they open.
 */
enum CollectionDisplayMode: string
{
    case Off = 'off';
    case Times = 'times';
    case TimesPredictions = 'times_predictions';

    public function showsTimes(): bool
    {
        return $this !== self::Off;
    }

    public function showsPredictions(): bool
    {
        return $this === self::TimesPredictions;
    }
}
