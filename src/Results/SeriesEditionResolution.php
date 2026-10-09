<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\SeriesEditionMatchKind;

/**
 * The answer of the matching rule for one series pick (SeriesEditionResolver, SeriesEditionMatch): the edition and how
 * it was found, or not identified (series-level - both null).
 */
readonly final class SeriesEditionResolution
{
    public function __construct(
        public null|string $competitionId,
        public null|SeriesEditionMatchKind $kind,
    ) {
    }

    public static function notIdentified(): self
    {
        return new self(null, null);
    }

    public function isIdentified(): bool
    {
        return $this->competitionId !== null;
    }
}
