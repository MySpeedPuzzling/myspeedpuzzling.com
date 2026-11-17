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
use InvalidArgumentException;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\StandingMissing;
use SpeedPuzzling\Web\Value\StandingMode;
use SpeedPuzzling\Web\Value\StandingOrder;
use SpeedPuzzling\Web\Value\StandingRules;

/**
 * An overall standing of one event across several of its rounds of one category (competition toolkit A6,
 * A-round-formats.md §3.2) - e.g. "World Series - Solo" = the sum of the six solo rounds. Computed on every read
 * (GetStandingResults + StandingCalculator), never stored. `round_ids` is the ordered list of the event's rounds it adds
 * up, validated on write (same event, the standing's category, no duplicates); a deleted round, or one moved to another
 * event, is pruned from it (DeleteCompetitionRoundHandler, MoveRoundToCompetitionHandler) and readers skip any id that
 * is not a round of the event and category any more (a round whose category changed later).
 *
 * `published_at` is the public page's switch (A7: publish() / unpublish(), event_standing / edition_standing). The unique (competition_id, slug) index serves
 * the event's list too.
 */
#[Entity]
#[UniqueConstraint(name: 'competition_standing_slug_unique', columns: ['competition_id', 'slug'])]
class CompetitionStanding
{
    public const int MAX_PER_COMPETITION = 20;
    public const int NAME_MAX_LENGTH = 80;
    public const int MAX_ROUNDS = 50;

    /**
     * @param list<string> $roundIds
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
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 16, enumType: RoundCategory::class)]
        public RoundCategory $category,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: self::NAME_MAX_LENGTH)]
        public string $name,
        // Unique per event, generated on create and kept on rename (like round slugs) - the public page's address (A7)
        #[Immutable]
        #[Column(length: 100)]
        public string $slug,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::JSONB)]
        public array $roundIds,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::SMALLINT, options: ['default' => 0])]
        public int $position = 0,
        #[Immutable]
        #[Column(length: 16, enumType: StandingMode::class, options: ['default' => 'sum'])]
        public StandingMode $mode = StandingMode::Sum,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::SMALLINT, options: ['default' => 0])]
        public int $unfinishedPenaltySecondsPerPiece = 0,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 32, enumType: StandingOrder::class, options: ['default' => 'finished_rounds_first'])]
        public StandingOrder $orderRule = StandingOrder::FinishedRoundsFirst,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(length: 32, enumType: StandingMissing::class, options: ['default' => 'incomplete'])]
        public StandingMissing $missingRule = StandingMissing::Incomplete,
        #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $publishedAt = null,
    ) {
    }

    /**
     * The organiser's label, trimmed and cut to NAME_MAX_LENGTH (the form refuses longer ones).
     *
     * @throws InvalidArgumentException for a name that is empty after trimming
     */
    public static function cleanName(string $name): string
    {
        $name = trim((string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $name));

        if ($name === '') {
            throw new InvalidArgumentException('A standing needs a name.');
        }

        return mb_substr($name, 0, self::NAME_MAX_LENGTH);
    }

    public function rules(): StandingRules
    {
        return new StandingRules($this->unfinishedPenaltySecondsPerPiece, $this->orderRule, $this->missingRule);
    }

    /**
     * @param list<string> $roundIds validated by the caller (CompetitionStandingRounds)
     */
    public function change(string $name, RoundCategory $category, array $roundIds, StandingRules $rules): void
    {
        $this->name = $name;
        $this->category = $category;
        $this->roundIds = $roundIds;
        $this->unfinishedPenaltySecondsPerPiece = $rules->penaltySecondsPerPiece;
        $this->orderRule = $rules->order;
        $this->missingRule = $rules->missing;
    }

    /**
     * The standing goes public (competition toolkit A7) - publishing again keeps the first moment.
     */
    public function publish(DateTimeImmutable $now): void
    {
        $this->publishedAt ??= $now;
    }

    public function unpublish(): void
    {
        $this->publishedAt = null;
    }

    /**
     * A round that is gone from the event (deleted, moved away) leaves the standing.
     *
     * @return bool whether the standing had it
     */
    public function pruneRound(string $roundId): bool
    {
        $roundId = strtolower($roundId);
        $kept = array_values(array_filter($this->roundIds, static fn (string $id): bool => strtolower($id) !== $roundId));

        if (count($kept) === count($this->roundIds)) {
            return false;
        }

        $this->roundIds = $kept;

        return true;
    }

    /**
     * @return list<string>
     */
    public function roundIds(): array
    {
        return array_map(strtolower(...), $this->roundIds);
    }
}
