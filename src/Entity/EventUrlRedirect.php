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
use SpeedPuzzling\Web\Value\EventUrlPath;

/**
 * An old event URL that keeps working after a restructuring tool moved what it showed (docs/features/organizations/
 * README.md, D6): moving an edition, moving a round, turning a series into an organization. The key spells the old path
 * (EventUrlPath, '' = not part of it); the target is exactly one of organization / series / competition / round - the
 * named constructor and pointTo() are the only way in - and resolves to its CURRENT URL at request time, so chained
 * moves keep working. Rows cascade with their target.
 */
#[Entity]
#[UniqueConstraint(name: 'event_url_redirect_path_unique', columns: ['series_slug', 'competition_slug', 'round_slug'])]
class EventUrlRedirect
{
    private function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[Column(options: ['default' => ''])]
        public string $seriesSlug,
        #[Immutable]
        #[Column(options: ['default' => ''])]
        public string $competitionSlug,
        #[Immutable]
        #[Column(options: ['default' => ''])]
        public string $roundSlug,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'CASCADE')]
        public null|Organization $organization,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(name: 'series_id', nullable: true, onDelete: 'CASCADE')]
        public null|CompetitionSeries $series,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(nullable: true, onDelete: 'CASCADE')]
        public null|Competition $competition,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[ManyToOne]
        #[JoinColumn(name: 'round_id', nullable: true, onDelete: 'CASCADE')]
        public null|CompetitionRound $round,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
    ) {
    }

    public static function to(
        UuidInterface $id,
        EventUrlPath $path,
        Organization|CompetitionSeries|Competition|CompetitionRound $target,
        DateTimeImmutable $createdAt,
    ): self {
        $redirect = new self($id, $path->seriesSlug, $path->competitionSlug, $path->roundSlug, null, null, null, null, $createdAt);
        $redirect->pointTo($target);

        return $redirect;
    }

    /**
     * A path moved again: the row points to the new target (the other three are cleared)
     */
    public function pointTo(Organization|CompetitionSeries|Competition|CompetitionRound $target): void
    {
        $this->organization = $target instanceof Organization ? $target : null;
        $this->series = $target instanceof CompetitionSeries ? $target : null;
        $this->competition = $target instanceof Competition ? $target : null;
        $this->round = $target instanceof CompetitionRound ? $target : null;
    }

    public function path(): EventUrlPath
    {
        return new EventUrlPath($this->seriesSlug, $this->competitionSlug, $this->roundSlug);
    }
}
