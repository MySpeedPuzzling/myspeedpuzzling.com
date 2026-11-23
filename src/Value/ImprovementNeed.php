<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The "What's missing" filter of "Puzzles needing improvement" (docs/features/puzzles-needing-improvement.md). The value
 * is the `missing` query parameter. WrongPicture is offered to admins and community moderators only - every other
 * viewer never sees those puzzles nor the badge, so for them it falls back to All.
 */
enum ImprovementNeed: string
{
    case All = '';
    case Ean = 'ean';
    case Image = 'image';
    case WrongPicture = 'wrong-picture';

    public static function fromQuery(string $value, bool $viewerModerates): self
    {
        $need = self::tryFrom($value) ?? self::All;

        if ($need === self::WrongPicture && $viewerModerates === false) {
            return self::All;
        }

        return $need;
    }
}
