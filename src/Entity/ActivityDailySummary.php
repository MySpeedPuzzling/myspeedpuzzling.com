<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Table;
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * Daily aggregate counts per locale - what analytics charts and marketing
 * decisions actually read. A few hundred rows a year, kept forever: unlike the
 * per-player raw table it contains no personal data, so it survives GDPR
 * deletions and the 24-month prune. Recomputed idempotently by the daily
 * snapshot cron (delete day + insert fresh).
 */
#[Entity]
#[Table(name: 'activity_daily_summary')]
#[UniqueConstraint(columns: ['day', 'locale'])]
class ActivityDailySummary
{
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[Column(type: Types::DATE_IMMUTABLE)]
        public DateTimeImmutable $day,
        // Player locale, 'unknown' when unset - never NULL, the (day, locale)
        // unique constraint must actually bite
        #[Immutable]
        #[Column(length: 10)]
        public string $locale,
        #[Immutable]
        #[Column(type: Types::INTEGER)]
        public int $activePlayers,
        #[Immutable]
        #[Column(type: Types::INTEGER)]
        public int $activeMembers,
        #[Immutable]
        #[Column(type: Types::INTEGER)]
        public int $newRegistrations,
        #[Immutable]
        #[Column(type: Types::DATETIMETZ_IMMUTABLE)]
        public DateTimeImmutable $computedAt,
    ) {
    }
}
