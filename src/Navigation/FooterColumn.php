<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * The footer's link columns in display order (docs/features/navigation README §4.11, FOOT-01): Puzzles · Community ·
 * Learn · MySpeedPuzzling. The value is the column's data-nav place of the links new in FOOT-01 (footer/<column>/<id>).
 */
enum FooterColumn: string
{
    case Puzzles = 'puzzles';
    case Community = 'community';
    case Learn = 'learn';
    case MySpeedPuzzling = 'myspeedpuzzling';

    /**
     * The heading's translation key - the Puzzles and Community places' own labels, so a place has one name on every
     * surface; null = the brand name, the same in every language.
     */
    public function headingKey(): null|string
    {
        return match ($this) {
            self::Puzzles => 'nav.row.puzzles_place',
            self::Community => 'community_page.title',
            self::Learn => 'footer.column.learn',
            self::MySpeedPuzzling => null,
        };
    }

    public function order(): int
    {
        return match ($this) {
            self::Puzzles => 1,
            self::Community => 2,
            self::Learn => 3,
            self::MySpeedPuzzling => 4,
        };
    }
}
