<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;

/**
 * A puzzle of a player's spreadsheet that is not in the catalogue - **the player's own, nobody else's**
 * (docs/features/data-import/unlisted-puzzles.md). Never a row of `puzzle`: no search, list, statistic, ranking, feed
 * or API reads this table, so nothing public can show it by accident. One per player and puzzle key - the same puzzle
 * from two sheets or two imports is one unlisted puzzle.
 *
 * `pairedPuzzleId` / `suggestedPuzzleId` are plain ids on purpose, not foreign keys: this table is tied to nothing of
 * the catalogue. A puzzle merged since is followed through `puzzle_redirect` when the id is used.
 */
#[Entity]
#[UniqueConstraint(columns: ['player_id', 'puzzle_key'])]
#[Index(columns: ['match_checked_at'])]
class UnlistedPuzzle
{
    #[Column(type: Types::TEXT, nullable: true)]
    public null|string $brandText = null;

    #[Column(nullable: true)]
    public null|int $piecesCount = null;

    /** @var array<mixed> barcodes its rows carried, each once */
    #[Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    public array $eans = [];

    /** @var array<mixed> brand codes its rows carried, each once */
    #[Column(type: Types::JSON, options: ['jsonb' => true, 'default' => '[]'])]
    public array $brandCodes = [];

    /** A link to a picture the file had for it - kept as text, never fetched */
    #[Column(type: Types::TEXT, nullable: true)]
    public null|string $pictureUrl = null;

    /** The catalogue puzzle the player said it is ("It is this catalogue puzzle") */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $pairedPuzzleId = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $pairedAt = null;

    /** "We think this is …": what the daily look at the grown catalogue found - the player confirms, nothing is paired by itself */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $suggestedPuzzleId = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $suggestedAt = null;

    /** The suggestion the player answered "not this one" - never made again */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: UuidType::NAME, nullable: true)]
    public null|UuidInterface $refusedSuggestionPuzzleId = null;

    /** When the daily look last asked the catalogue about it - the oldest are asked first */
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $matchCheckedAt = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $player,
        /** `<version>:<sha256>` of name + pieces + brand as the rows say them (ImportPuzzleKey) */
        #[Immutable]
        #[Column(length: 80)]
        public string $puzzleKey,
        /** As the file writes it */
        #[Column(type: Types::TEXT)]
        public string $name,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
        /** The import whose row first brought it - a plain id, the import may go */
        #[Immutable]
        #[Column(type: UuidType::NAME, nullable: true)]
        public null|UuidInterface $createdByImportId = null,
    ) {
    }

    public function isOwnedBy(string $playerId): bool
    {
        return $this->player->id->toString() === strtolower($playerId);
    }

    /**
     * Another row of the same puzzle key says more: codes are added, a missing brand, piece count or picture link is
     * filled - the unlisted puzzle is updated, never doubled.
     *
     * @param array<mixed> $eans
     * @param array<mixed> $brandCodes
     */
    public function learnedFromRow(null|string $brandText, null|int $piecesCount, array $eans, array $brandCodes, null|string $pictureUrl): void
    {
        $this->brandText ??= $brandText;
        $this->piecesCount ??= $piecesCount;
        $this->pictureUrl ??= $pictureUrl;
        $this->eans = self::union($this->eans, $eans);
        $this->brandCodes = self::union($this->brandCodes, $brandCodes);
    }

    /**
     * The player's word: it is this catalogue puzzle. A suggestion is answered by it.
     */
    public function pairedWith(UuidInterface $puzzleId, DateTimeImmutable $now): void
    {
        $this->pairedPuzzleId = $puzzleId;
        $this->pairedAt = $now;
        $this->suggestedPuzzleId = null;
        $this->suggestedAt = null;
    }

    /**
     * An Undo of the pairing took everything back: it is unlisted again.
     */
    public function unpaired(): void
    {
        $this->pairedPuzzleId = null;
        $this->pairedAt = null;
    }

    /**
     * The daily look found one strong, unique match. Nothing is paired: the player confirms it on the page.
     *
     * @return bool whether this is a new suggestion (the player is told once)
     */
    public function suggested(UuidInterface $puzzleId, DateTimeImmutable $now): bool
    {
        $this->matchCheckedAt = $now;

        if ($this->pairedPuzzleId !== null || $this->refusedSuggestionPuzzleId?->equals($puzzleId) === true) {
            return false;
        }

        if ($this->suggestedPuzzleId?->equals($puzzleId) === true) {
            return false;
        }

        $this->suggestedPuzzleId = $puzzleId;
        $this->suggestedAt = $now;

        return true;
    }

    /**
     * The daily look found nothing certain: a suggestion made before stays (the player has been told of it).
     */
    public function nothingFound(DateTimeImmutable $now): void
    {
        $this->matchCheckedAt = $now;
    }

    /**
     * "Not this one": the suggestion goes and is never made again.
     */
    public function suggestionRefused(): void
    {
        $this->refusedSuggestionPuzzleId = $this->suggestedPuzzleId ?? $this->refusedSuggestionPuzzleId;
        $this->suggestedPuzzleId = null;
        $this->suggestedAt = null;
    }

    /**
     * @param array<mixed> $known
     * @param array<mixed> $more
     *
     * @return list<string>
     */
    private static function union(array $known, array $more): array
    {
        $all = [];

        foreach ([...$known, ...$more] as $code) {
            if (is_string($code) && $code !== '') {
                $all[$code] = true;
            }
        }

        return array_map(strval(...), array_keys($all));
    }
}
