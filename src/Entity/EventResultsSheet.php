<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * An organizer's results spreadsheet MySpeedPuzzling reads on a schedule (docs/features/event-results-import/README.md):
 * every tab becomes a one-time event of the organization. The spreadsheet id lives here only, never in the repo.
 */
#[Entity]
class EventResultsSheet
{
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $lastFetchedAt = null;

    // Why the last read failed (not shared with the link, Google unreachable) - null after a successful one
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::TEXT, nullable: true)]
    public null|string $lastFetchError = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[Column(length: 100, unique: true)]
        public string $spreadsheetId,
        // The events land here; they are approved at once when the organization is (OrganizationApprovalPolicy)
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Organization $organization,
        // Credited as the events' creator - somebody on the organization's team
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $actingPlayer,
        // The zone the sheet's start times are written in - a country's main zone is not enough (Minnesota is not on
        // New York time)
        #[Immutable]
        #[Column(length: 64)]
        public string $timezone,
        #[Immutable]
        #[Column(length: 2)]
        public string $countryCode,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $addedAt,
    ) {
    }

    public function fetched(DateTimeImmutable $at): void
    {
        $this->lastFetchedAt = $at;
        $this->lastFetchError = null;
    }

    public function fetchFailed(DateTimeImmutable $at, string $error): void
    {
        $this->lastFetchedAt = $at;
        $this->lastFetchError = $error;
    }
}
