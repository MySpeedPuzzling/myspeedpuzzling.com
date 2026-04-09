<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\RoundCategory;

readonly final class EditionRoundDetail
{
    /**
     * @param array<EditionRoundPuzzle> $puzzles
     */
    public function __construct(
        public string $id,
        public string $name,
        public DateTimeImmutable $startsAt,
        public int $minutesLimit,
        public RoundCategory $category,
        public null|string $badgeBackgroundColor,
        public null|string $badgeTextColor,
        public array $puzzles,
    ) {
    }
}
