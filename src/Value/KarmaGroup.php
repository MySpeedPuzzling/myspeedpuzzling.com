<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The filters of the public karma list: new puzzles, fixes & merges, moderation (docs/features/karma.md).
 */
enum KarmaGroup: string
{
    case Puzzles = 'puzzles';
    case Fixes = 'fixes';
    case Moderation = 'moderation';

    /**
     * @return list<string> KarmaKind values
     */
    public function kindValues(): array
    {
        $values = [];

        foreach (KarmaKind::cases() as $kind) {
            if ($kind->group() === $this) {
                $values[] = $kind->value;
            }
        }

        return $values;
    }
}
