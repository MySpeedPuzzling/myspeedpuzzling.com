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
use SpeedPuzzling\Web\Value\FollowTarget;

/**
 * A player follows a one-time event or a whole series (docs/features/events-page/README.md, "Follow"). Exactly one
 * of the two targets is set - the named constructors are the only way in. An edition is never followed on its own:
 * its star follows the series. Rows cascade with the player, the competition and the series.
 */
#[Entity]
#[UniqueConstraint(columns: ['player_id', 'competition_id'])]
#[UniqueConstraint(columns: ['player_id', 'series_id'])]
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

    /**
     * ConvertCompetitionToSeriesHandler: the followed event became the first edition of a new series - its followers
     * follow the series from now on.
     */
    public function moveToSeries(CompetitionSeries $series): void
    {
        $this->competition = null;
        $this->series = $series;
    }

    public function target(): FollowTarget
    {
        if ($this->series !== null) {
            return FollowTarget::series($this->series->id->toString());
        }

        assert($this->competition !== null);

        return FollowTarget::competition($this->competition->id->toString());
    }
}
