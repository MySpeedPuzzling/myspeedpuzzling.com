<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\DatePrecision;
use SpeedPuzzling\Web\Value\Puzzler;
use SpeedPuzzling\Web\Value\PuzzlingType;

/**
 * An unfinished result on the puzzle page (docs/features/competitions-management/unfinished-results-plan.md "Puzzle
 * detail page"): the pieces placed when a competition round's time ran out, shown after every ranked row, never ranked.
 * Its own type on purpose - it has no time, so it can never reach the ranked pipeline (PuzzleSolver::$time is an int,
 * formatted by puzzlingTime everywhere a ranked row is shown).
 *
 * The property names are the ones the leaderboard partials read (`players`, `teamId`, `teamName`, `timeId`,
 * `firstAttempt`, `unboxed`, `competition*`, `finishedAt`, `datePrecision`). A solo result has its player as the one
 * element of $players.
 */
readonly final class UnfinishedPuzzleResult
{
    public function __construct(
        public string $timeId,
        public string $puzzleId,
        public PuzzlingType $puzzlingType,
        // Whoever tracked it; null for an imported event result nobody has claimed yet
        public null|string $addedByPlayerId,
        /** @var list<Puzzler> */
        public array $players,
        public int $piecesPlaced,
        // The total when they finished it after the time ran out - display only
        public null|int $finishedLaterSeconds,
        public null|DateTimeImmutable $finishedAt,
        public DateTimeImmutable $trackedAt,
        public bool $firstAttempt,
        public bool $unboxed,
        public null|string $competitionId,
        public null|string $competitionShortcut,
        public null|string $competitionName,
        public null|string $competitionSlug,
        public null|string $competitionSeriesName = null,
        public null|string $competitionSeriesShortcut = null,
        public null|string $competitionSeriesSlug = null,
        public null|string $teamId = null,
        public null|string $teamName = null,
        public bool $importedFromEvent = false,
        public DatePrecision $datePrecision = DatePrecision::Day,
    ) {
    }

    /**
     * @param array{
     *     time_id: string,
     *     puzzle_id: string,
     *     puzzling_type: string,
     *     player_id: null|string,
     *     players: string,
     *     pieces_placed: int,
     *     finished_later_seconds: null|int,
     *     finished_at: null|string,
     *     finished_at_precision: null|string,
     *     tracked_at: string,
     *     first_attempt: bool,
     *     unboxed: bool,
     *     competition_id: null|string,
     *     competition_shortcut: null|string,
     *     competition_name: null|string,
     *     competition_slug: null|string,
     *     competition_series_name: null|string,
     *     competition_series_shortcut: null|string,
     *     competition_series_slug: null|string,
     *     team_id: null|string,
     *     team_name: null|string,
     *     imported_from_event: null|bool,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            timeId: $row['time_id'],
            puzzleId: $row['puzzle_id'],
            puzzlingType: PuzzlingType::from($row['puzzling_type']),
            addedByPlayerId: $row['player_id'],
            players: array_values(Puzzler::createPuzzlersFromJson($row['players'])),
            piecesPlaced: (int) $row['pieces_placed'],
            finishedLaterSeconds: $row['finished_later_seconds'] !== null ? (int) $row['finished_later_seconds'] : null,
            finishedAt: $row['finished_at'] !== null ? new DateTimeImmutable($row['finished_at']) : null,
            trackedAt: new DateTimeImmutable($row['tracked_at']),
            firstAttempt: $row['first_attempt'],
            unboxed: $row['unboxed'],
            competitionId: $row['competition_id'],
            competitionShortcut: $row['competition_shortcut'],
            competitionName: $row['competition_name'],
            competitionSlug: $row['competition_slug'],
            competitionSeriesName: $row['competition_series_name'],
            competitionSeriesShortcut: $row['competition_series_shortcut'],
            competitionSeriesSlug: $row['competition_series_slug'],
            teamId: $row['puzzling_type'] === PuzzlingType::Solo->value ? null : $row['team_id'],
            teamName: $row['puzzling_type'] === PuzzlingType::Solo->value ? null : $row['team_name'],
            importedFromEvent: ($row['imported_from_event'] ?? false) === true,
            datePrecision: DatePrecision::ofStored($row['finished_at_precision']),
        );
    }

    public function isSolo(): bool
    {
        return $this->puzzlingType === PuzzlingType::Solo;
    }

    public function containsPlayer(null|string $playerId): bool
    {
        return $playerId !== null && Puzzler::listContainsPlayer($this->players, $playerId);
    }

    /**
     * Mirrors PuzzleSolver::isEditableBy() (solo: its player) and PuzzleSolversGroup::isEditableBy() (pair/team: the
     * tracker and every registered member, never an imported event result).
     */
    public function isEditableBy(null|string $playerId): bool
    {
        if ($playerId === null || $this->importedFromEvent) {
            return false;
        }

        if ($this->isSolo()) {
            return $playerId === $this->addedByPlayerId;
        }

        return $playerId === $this->addedByPlayerId || $this->containsPlayer($playerId);
    }

    /**
     * Nobody of an imported event result has claimed a spot yet - the people cell shows the team's name instead
     */
    public function nobodyClaimed(): bool
    {
        foreach ($this->players as $player) {
            if ($player->anonymousSlot === null) {
                return false;
            }
        }

        return $this->players !== [];
    }
}
