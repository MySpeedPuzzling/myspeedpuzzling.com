<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\DatePrecision;
use SpeedPuzzling\Web\Value\Puzzler;

readonly final class RecentActivityItem
{
    public function __construct(
        public string $id,
        public string $playerId,
        public null|string $playerName,
        public string $playerCode,
        public null|CountryCode $playerCountry,
        public string $puzzleId,
        public string $puzzleName,
        public string $manufacturerName,
        public int $piecesCount,
        public null|int $time,
        public null|string $puzzleImage,
        public null|float $puzzleImageRatio,
        public null|string $comment,
        public DateTimeImmutable $trackedAt,
        public null|DateTimeImmutable $finishedAt,
        public null|string $finishedPuzzlePhoto,
        public null|string $teamId,
        /** @var null|array<Puzzler> */
        public null|array $players,
        public null|string $puzzleIdentificationNumber,
        public bool $firstAttempt,
        public bool $unboxed,
        public bool $isPrivate,
        public null|string $competitionId,
        public null|string $competitionShortcut,
        public null|string $competitionName,
        public null|string $competitionSlug,
        public null|string $skillTierName = null,
        public bool $rankingOptedOut = false,
        public null|string $competitionSeriesName = null,
        public null|string $competitionSeriesShortcut = null,
        public null|string $competitionSeriesSlug = null,
        public null|string $playerAvatar = null,
        // Only the player's own feed lists a suspicious time (labelled) - the shared feeds leave it out
        public bool $suspicious = false,
        // How exactly the solve day is known (date-precision.md): a day, or only its month or year
        public DatePrecision $datePrecision = DatePrecision::Day,
        // An unfinished competition result (docs/features/competitions-management/unfinished-results-plan.md): the
        // pieces placed when the round's time ran out - no time, and no relax solve
        public null|int $piecesPlaced = null,
        public null|int $finishedLaterSeconds = null,
        // An imported event result (docs/features/event-results-import/README.md "Gates"): the event's - never edited
        // nor deleted, its people may only remove it from their profile
        public bool $importedFromEvent = false,
        // A relay team's result (docs/features/event-results-import/solo-and-relays.md §2): no time of its own - the
        // team's total of all the relay's puzzles, how many puzzles, and the round that ranks it (the row's link)
        public null|int $relayTotalSeconds = null,
        public int $relayPuzzleCount = 0,
        public null|string $relayRoundSlug = null,
    ) {
    }

    public function isRelaxMode(): bool
    {
        return $this->time === null && $this->piecesPlaced === null && $this->relayTotalSeconds === null;
    }

    /**
     * One of a relay team's results - shown as "Relay · N puzzles · total", linked to the event's round, never as relax
     */
    public function isRelay(): bool
    {
        return $this->relayTotalSeconds !== null;
    }

    public function isUnfinished(): bool
    {
        return $this->piecesPlaced !== null;
    }

    public function isSpeedMode(): bool
    {
        return $this->time !== null;
    }

    /**
     * @param array{
     *     time_id: string,
     *     player_id: string,
     *     player_name: null|string,
     *     player_code: string,
     *     player_country: null|string,
     *     puzzle_id: string,
     *     puzzle_name: string,
     *     manufacturer_name: string,
     *     puzzle_image: null|string,
     *     puzzle_image_ratio: null|string,
     *     time: null|int,
     *     pieces_count: int,
     *     comment: null|string,
     *     tracked_at: string,
     *     finished_at: null|string,
     *     finished_at_precision?: null|string,
     *     finished_puzzle_photo: null|string,
     *     puzzle_identification_number: null|string,
     *     team_id?: null|string,
     *     players?: null|string|array<Puzzler>,
     *     player_avatar?: null|string,
     *     first_attempt: bool,
     *     unboxed: bool,
     *     is_private?: bool,
     *     competition_id: null|string,
     *     competition_name: null|string,
     *     competition_shortcut: null|string,
     *     competition_slug: null|string,
     *     competition_series_name: null|string,
     *     competition_series_shortcut: null|string,
     *     competition_series_slug: null|string,
     *     skill_tier_name?: null|string,
     *     ranking_opted_out?: bool,
     *     suspicious?: bool,
     *     pieces_placed?: null|int,
     *     finished_later_seconds?: null|int,
     *     imported_from_event?: bool,
     *     relay_total_seconds?: null|int,
     *     relay_puzzle_count?: null|int,
     *     relay_round_slug?: null|string,
     *     ...
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $players = null;

        if (is_array($row['players'] ?? null)) {
            $players = $row['players'];
        }

        if (is_string($row['players'] ?? null)) {
            $players = Puzzler::createPuzzlersFromJson($row['players']);
        }

        return new self(
            id: $row['time_id'],
            playerId: $row['player_id'],
            playerName: $row['player_name'],
            playerCode: strtoupper($row['player_code']),
            playerCountry: CountryCode::fromCode($row['player_country']),
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            manufacturerName: $row['manufacturer_name'],
            piecesCount: $row['pieces_count'],
            time: $row['time'],
            puzzleImage: $row['puzzle_image'],
            puzzleImageRatio: $row['puzzle_image_ratio'] !== null ? (float) $row['puzzle_image_ratio'] : null,
            comment: $row['comment'],
            trackedAt: new DateTimeImmutable($row['tracked_at']),
            finishedAt: $row['finished_at'] !== null ? new DateTimeImmutable($row['finished_at']) : null,
            finishedPuzzlePhoto: $row['finished_puzzle_photo'],
            teamId: $row['team_id'] ?? null,
            players: $players,
            puzzleIdentificationNumber: $row['puzzle_identification_number'],
            firstAttempt: $row['first_attempt'],
            unboxed: $row['unboxed'],
            isPrivate: $row['is_private'] ?? false,
            competitionId: $row['competition_id'],
            competitionShortcut: $row['competition_shortcut'],
            competitionName: $row['competition_name'],
            competitionSlug: $row['competition_slug'],
            competitionSeriesName: $row['competition_series_name'],
            competitionSeriesShortcut: $row['competition_series_shortcut'],
            competitionSeriesSlug: $row['competition_series_slug'],
            skillTierName: $row['skill_tier_name'] ?? null,
            rankingOptedOut: $row['ranking_opted_out'] ?? false,
            playerAvatar: $row['player_avatar'] ?? null,
            suspicious: $row['suspicious'] ?? false,
            datePrecision: DatePrecision::ofStored($row['finished_at_precision'] ?? null),
            piecesPlaced: isset($row['pieces_placed']) ? (int) $row['pieces_placed'] : null,
            finishedLaterSeconds: isset($row['finished_later_seconds']) ? (int) $row['finished_later_seconds'] : null,
            importedFromEvent: ($row['imported_from_event'] ?? false) === true,
            relayTotalSeconds: isset($row['relay_total_seconds']) ? (int) $row['relay_total_seconds'] : null,
            relayPuzzleCount: isset($row['relay_puzzle_count']) ? (int) $row['relay_puzzle_count'] : 0,
            relayRoundSlug: $row['relay_round_slug'] ?? null,
        );
    }

    /**
     * Whoever tracked the time and every registered group member may edit it,
     * mirroring PuzzleSolvingTime::canBeModifiedBy() - never an imported event result, that one is the event's.
     */
    public function isEditableBy(null|string $playerId): bool
    {
        if ($playerId === null || $this->importedFromEvent) {
            return false;
        }

        return $playerId === $this->playerId || Puzzler::listContainsPlayer($this->players, $playerId);
    }

    /**
     * An imported event result the player holds a spot of: "Remove from my profile" instead of Edit and Delete
     * (docs/features/event-results-import/README.md "Gates"), like SolvedPuzzle::isReleasableBy().
     */
    public function isReleasableBy(null|string $playerId): bool
    {
        if ($playerId === null || $this->importedFromEvent === false) {
            return false;
        }

        return $playerId === $this->playerId || Puzzler::listContainsPlayer($this->players, $playerId);
    }
}
