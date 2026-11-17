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
use SpeedPuzzling\Web\Value\AdvanceDistribution;
use SpeedPuzzling\Web\Value\AdvancementQualifyScope;
use SpeedPuzzling\Web\Value\AdvancementStepSettings;

/**
 * A declared advancement step of an event (competition toolkit A8, A-round-formats.md §3.5): "from round(s) X into
 * round(s) Y, how many qualify, how they are spread" - exactly what AdvanceQualified takes, stored, so the results desk
 * can offer it as a preset (a step banner, "Mark top N", Advance prefilled). It never marks or moves anybody by
 * itself: qualification stays the organiser's decision (Jan, PR #136).
 *
 * The steps of an event form a graph checked on every write (AdvancementStepGraph): same category per step, sources and
 * targets disjoint, a round fed by one step at most, no cycles. A round deleted or moved away is pruned
 * (DeleteCompetitionRoundHandler, MoveRoundToCompetitionHandler); a step left without sources or targets goes with it.
 */
#[Entity]
class CompetitionAdvancementStep
{
    public const int MAX_PER_COMPETITION = 50;
    public const int NAME_MAX_LENGTH = 80;
    public const int MAX_ROUNDS = 50;

    /**
     * @param list<string> $sourceRoundIds
     * @param list<string> $targetRoundIds
     * @param array<string, string> $targetBySource
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Competition $competition,
        // The organiser's label ("Groups → Semifinals"); null = the pages name it by its rounds
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: self::NAME_MAX_LENGTH, nullable: true)]
        public null|string $name,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::JSONB)]
        public array $sourceRoundIds,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::JSONB)]
        public array $targetRoundIds,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 16, enumType: AdvanceDistribution::class)]
        public AdvanceDistribution $distribution,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::JSONB, options: ['default' => '{}'])]
        public array $targetBySource = [],
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::SMALLINT, nullable: true)]
        public null|int $qualifyCount = null,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 16, enumType: AdvancementQualifyScope::class, options: ['default' => 'per_round'])]
        public AdvancementQualifyScope $qualifyScope = AdvancementQualifyScope::PerRound,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::SMALLINT, nullable: true)]
        public null|int $bestOfEachCountry = null,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::SMALLINT, options: ['default' => 0])]
        public int $position = 0,
    ) {
    }

    /**
     * The organiser's label, trimmed and cut to NAME_MAX_LENGTH; empty = null (the pages name the step by its rounds).
     */
    public static function cleanName(null|string $name): null|string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name ?? ''));

        return $name === '' ? null : mb_substr($name, 0, self::NAME_MAX_LENGTH);
    }

    public function settings(): AdvancementStepSettings
    {
        /** @var array<string, string> $map */
        $map = $this->targetBySource;

        return new AdvancementStepSettings(
            sourceRoundIds: array_map(strtolower(...), $this->sourceRoundIds),
            targetRoundIds: array_map(strtolower(...), $this->targetRoundIds),
            distribution: $this->distribution,
            targetBySource: $map,
            qualifyCount: $this->qualifyCount,
            qualifyScope: $this->qualifyScope,
            bestOfEachCountry: $this->bestOfEachCountry,
        );
    }

    /**
     * @param AdvancementStepSettings $settings normalised and validated by the caller (AdvancementStepGraph)
     */
    public function change(null|string $name, AdvancementStepSettings $settings): void
    {
        $this->name = $name;
        $this->sourceRoundIds = $settings->sourceRoundIds;
        $this->targetRoundIds = $settings->targetRoundIds;
        $this->distribution = $settings->distribution;
        $this->targetBySource = $settings->targetBySource;
        $this->qualifyCount = $settings->qualifyCount;
        $this->qualifyScope = $settings->qualifyScope;
        $this->bestOfEachCountry = $settings->bestOfEachCountry;
    }

    /**
     * A round that is gone from the event (deleted, moved away) leaves the step: from its sources, targets and map.
     * "Spread evenly" left with one target becomes "all into one round"; a by-source map that lost a target stays
     * incomplete until the organiser fixes it (the steps page says so, the desk shows no banner for it).
     *
     * @return bool whether the step had it
     */
    public function pruneRound(string $roundId): bool
    {
        $roundId = strtolower($roundId);
        $keep = static fn (string $id): bool => strtolower($id) !== $roundId;

        $sources = array_values(array_filter($this->sourceRoundIds, $keep));
        $targets = array_values(array_filter($this->targetRoundIds, $keep));

        if (count($sources) === count($this->sourceRoundIds) && count($targets) === count($this->targetRoundIds)) {
            return false;
        }

        $map = [];
        foreach ($this->targetBySource as $source => $target) {
            if ($keep((string) $source) && $keep($target)) {
                $map[(string) $source] = $target;
            }
        }

        $this->sourceRoundIds = $sources;
        $this->targetRoundIds = $targets;
        $this->targetBySource = $map;

        if ($this->distribution === AdvanceDistribution::Balanced && count($targets) === 1) {
            $this->distribution = AdvanceDistribution::Single;
        }

        return true;
    }

    /**
     * A step without a source or a target round means nothing any more - it is removed.
     */
    public function isEmpty(): bool
    {
        return $this->sourceRoundIds === [] || $this->targetRoundIds === [];
    }
}
