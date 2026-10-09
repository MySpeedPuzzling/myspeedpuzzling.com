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
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * A player follows a one-time event, a whole series (docs/features/events-page/README.md, "Follow") or an
 * organization (docs/features/organizations/README.md, "Follow"). Exactly one of the three targets is set - the named
 * constructors are the only way in (no DB check). An edition is never followed on its own: its star follows the
 * series. Rows cascade with the player, the competition, the series and the organization.
 */
#[Entity]
#[UniqueConstraint(columns: ['player_id', 'competition_id'])]
#[UniqueConstraint(columns: ['player_id', 'series_id'])]
#[UniqueConstraint(columns: ['player_id', 'organization_id'])]
class FollowedCompetition
{
    private function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $player,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'CASCADE')]
        public null|Competition $competition,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(name: 'series_id', nullable: true, onDelete: 'CASCADE')]
        public null|CompetitionSeries $series,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(name: 'organization_id', nullable: true, onDelete: 'CASCADE')]
        public null|Organization $organization = null,
    ) {
    }

    public static function ofCompetition(UuidInterface $id, Player $player, Competition $competition, DateTimeImmutable $createdAt): self
    {
        return new self($id, $player, $competition, null, $createdAt);
    }

    public static function ofSeries(UuidInterface $id, Player $player, CompetitionSeries $series, DateTimeImmutable $createdAt): self
    {
        return new self($id, $player, null, $series, $createdAt);
    }

    public static function ofOrganization(UuidInterface $id, Player $player, Organization $organization, DateTimeImmutable $createdAt): self
    {
        return new self($id, $player, null, null, $createdAt, $organization);
    }

    /**
     * CreateOrganizationFromSeries: the followed series became an organization's - its followers follow the
     * organization from now on (a player following both keeps one row: the caller removes this one instead).
     */
    public function moveToOrganization(Organization $organization): void
    {
        $this->competition = null;
        $this->series = null;
        $this->organization = $organization;
    }

    /**
     * ConvertCompetitionToSeriesHandler: the followed event became the first edition of a new series - its followers
     * follow the series from now on.
     */
    public function moveToSeries(CompetitionSeries $series): void
    {
        $this->competition = null;
        $this->series = $series;
    }
}
