<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\DatePrecision;
use SpeedPuzzling\Web\Value\PuzzleNames;
use SpeedPuzzling\Web\Value\Puzzler;

readonly final class SolvedPuzzleDetail
{
    public function __construct(
        public string $timeId,
        public null|string $teamId,
        public string $playerId,
        public string $puzzleId,
        public string $puzzleName,
        public PuzzleNames $puzzleAlternativeNames,
        public string $manufacturerName,
        public string $manufacturerId,
        public int $piecesCount,
        public null|int $time,
        public null|string $puzzleImage,
        public null|float $puzzleImageRatio,
        public null|string $comment,
        /** @var null|array<Puzzler> */
        public null|array $players,
        public null|DateTimeImmutable $finishedAt,
        public null|string $finishedPuzzlePhoto,
        public bool $firstAttempt,
        public bool $unboxed,
        public null|string $competitionId,
        // The puzzle has an image, held back until a competition round starts (puzzleImage is null then)
        public bool $puzzleImageHidden = false,
        // When the result was saved - with finishedAt where it sits in the player's history (SolveMoment)
        public null|DateTimeImmutable $trackedAt = null,
        // The series of a series pick (docs/features/events-page/high-frequency-series.md) - then competitionId is the
        // edition MySpeedPuzzling found, or null (series-level). Null for an explicit link
        public null|string $seriesPickId = null,
        // competitionId is an edition of a series (picked explicitly unless seriesPickId is set) - CompetitionPick::ofTime()
        public bool $competitionIsEdition = false,
        // An imported event result (docs/features/event-results-import/README.md "Gates"): the event's - nobody edits or
        // deletes it, its claimers release their spot instead
        public bool $importedFromEvent = false,
        // How exactly the solve day is known (date-precision.md): a day, or only its month or year
        public DatePrecision $datePrecision = DatePrecision::Day,
        // An unfinished competition result (docs/features/competitions-management/unfinished-results-plan.md): pieces
        // placed when the round's time ran out ($time is null - no relax solve), the time spent, the later total
        public null|int $piecesPlaced = null,
        public null|int $secondsSpent = null,
        public null|int $finishedLaterSeconds = null,
        // The competition round of the result, when its event is public - "See round results" on the recap
        public null|string $roundName = null,
        public null|string $roundSlug = null,
        public null|string $roundCompetitionSlug = null,
        public null|string $roundSeriesSlug = null,
    ) {
    }

    /**
     * @param array{
     *     time_id: string,
     *     team_id: null|string,
     *     player_id: string,
     *     puzzle_id: string,
     *     puzzle_name: string,
     *     puzzle_alternative_names: string,
     *     manufacturer_name: string,
     *     manufacturer_id: string,
     *     puzzle_image: null|string,
     *     puzzle_image_ratio: null|string,
     *     puzzle_image_hidden: bool,
     *     time: null|int,
     *     pieces_count: int,
     *     comment: null|string,
     *     players: null|string,
     *     finished_at: null|string,
     *     finished_at_precision?: null|string,
     *     tracked_at?: null|string,
     *     finished_puzzle_photo: string,
     *     first_attempt: bool,
     *     unboxed: bool,
     *     competition_id: null|string,
     *     series_pick_id?: null|string,
     *     competition_is_edition?: null|bool,
     *     imported_from_event?: null|bool,
     *     pieces_placed?: null|int,
     *     seconds_spent?: null|int,
     *     finished_later_seconds?: null|int,
     *     round_name?: null|string,
     *     round_slug?: null|string,
     *     round_competition_slug?: null|string,
     *     round_series_slug?: null|string,
     *  } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $players = null;
        if ($row['players'] !== null) {
            $players = Puzzler::createPuzzlersFromJson($row['players'], $row['player_id']);
        }

        return new self(
            timeId: $row['time_id'],
            teamId: $row['team_id'],
            playerId: $row['player_id'],
            puzzleId: $row['puzzle_id'],
            puzzleName: $row['puzzle_name'],
            puzzleAlternativeNames: PuzzleNames::fromJson($row['puzzle_alternative_names']),
            manufacturerName: $row['manufacturer_name'],
            manufacturerId: $row['manufacturer_id'],
            piecesCount: $row['pieces_count'],
            time: $row['time'],
            puzzleImage: $row['puzzle_image'],
            puzzleImageRatio: $row['puzzle_image_ratio'] !== null ? (float) $row['puzzle_image_ratio'] : null,
            comment: $row['comment'],
            players: $players,
            finishedAt: $row['finished_at'] !== null ? new DateTimeImmutable($row['finished_at']) : null,
            finishedPuzzlePhoto: $row['finished_puzzle_photo'],
            firstAttempt: $row['first_attempt'],
            unboxed: $row['unboxed'],
            competitionId: $row['competition_id'],
            puzzleImageHidden: $row['puzzle_image_hidden'],
            trackedAt: isset($row['tracked_at']) ? new DateTimeImmutable($row['tracked_at']) : null,
            seriesPickId: $row['series_pick_id'] ?? null,
            competitionIsEdition: ($row['competition_is_edition'] ?? false) === true,
            importedFromEvent: ($row['imported_from_event'] ?? false) === true,
            datePrecision: DatePrecision::ofStored($row['finished_at_precision'] ?? null),
            piecesPlaced: isset($row['pieces_placed']) ? (int) $row['pieces_placed'] : null,
            secondsSpent: isset($row['seconds_spent']) ? (int) $row['seconds_spent'] : null,
            finishedLaterSeconds: isset($row['finished_later_seconds']) ? (int) $row['finished_later_seconds'] : null,
            roundName: $row['round_name'] ?? null,
            roundSlug: $row['round_slug'] ?? null,
            roundCompetitionSlug: $row['round_competition_slug'] ?? null,
            roundSeriesSlug: $row['round_series_slug'] ?? null,
        );
    }

    public function isUnfinished(): bool
    {
        return $this->piecesPlaced !== null;
    }

    /**
     * Whoever tracked the time and every registered group member may edit it,
     * mirroring PuzzleSolvingTime::canBeModifiedBy().
     */
    public function isEditableBy(null|string $playerId): bool
    {
        if ($playerId === null || $this->importedFromEvent) {
            return false;
        }

        return $playerId === $this->playerId || Puzzler::listContainsPlayer($this->players, $playerId);
    }
}
