<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;

readonly final class BadgeCatalogEntry
{
    public function __construct(
        public BadgeType $type,
        public null|BadgeTier $tier,
        public string $requirementTranslationKey,
        public bool $earned,
        public null|DateTimeImmutable $earnedAt,
    ) {
    }
}
