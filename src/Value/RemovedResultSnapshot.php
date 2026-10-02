<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;

/**
 * Everything of a result that was removed automatically, so Undo can bring the very same row back
 * (docs/features/duplicate-results.md, "Automatic removal"). Stored as JSON in result_auto_removal.snapshot.
 *
 * The group is kept as its JSON snapshot (the `team` column); its puzzling_team is resolved again from it on
 * Undo - it is the same set of people, so it is the same team unless the team was cleaned up meanwhile.
 */
readonly final class RemovedResultSnapshot
{
    private const string DATE_FORMAT = 'Y-m-d H:i:s';

    /**
     * @param null|array{team_id: null|string, puzzlers: list<array{player_id: null|string, player_name: null|string}>} $team
     */
    public function __construct(
        public string $id,
        public null|int $secondsToSolve,
        public string $playerId,
        public string $puzzleId,
        // For whoever reads the snapshot later without the puzzle (admin, e-mail)
        public string $puzzleName,
        public DateTimeImmutable $trackedAt,
        public bool $verified,
        public null|array $team,
        public null|string $puzzlingTeamId,
        public null|DateTimeImmutable $finishedAt,
        public null|string $comment,
        public null|string $finishedPuzzlePhoto,
        public bool $firstAttempt,
        public bool $unboxed,
        public null|string $competitionId,
        public null|string $competitionRoundId,
        public null|int $piecesPlaced,
        public null|bool $qualified,
        public bool $suspicious,
        public null|int $finishedLaterSeconds,
        public null|SolvingTimeSource $createdVia,
        public null|bool $predictable,
        public null|TimePredictionMethod $predictionMethod,
        public null|int $predictedSeconds,
        public null|int $predictedRangeLowSeconds,
        public null|int $predictedRangeHighSeconds,
        public null|int $predictedAttemptNumber,
        public null|int $predictionLastTimeSeconds,
        public null|TimePredictionSource $predictionSource,
        public null|DateTimeImmutable $predictionComputedAt,
        public null|int $predictionModelVersion,
    ) {
    }

    public static function of(PuzzleSolvingTime $time): self
    {
        $team = null;

        if ($time->team !== null) {
            $team = ['team_id' => $time->team->teamId, 'puzzlers' => []];

            foreach ($time->team->puzzlers as $puzzler) {
                $team['puzzlers'][] = ['player_id' => $puzzler->playerId, 'player_name' => $puzzler->playerName];
            }
        }

        return new self(
            id: $time->id->toString(),
            secondsToSolve: $time->secondsToSolve,
            playerId: $time->player->id->toString(),
            puzzleId: $time->puzzle->id->toString(),
            puzzleName: $time->puzzle->name,
            trackedAt: $time->trackedAt,
            verified: $time->verified,
            team: $team,
            puzzlingTeamId: $time->puzzlingTeam?->id->toString(),
            finishedAt: $time->finishedAt,
            comment: $time->comment,
            finishedPuzzlePhoto: $time->finishedPuzzlePhoto,
            firstAttempt: $time->firstAttempt,
            unboxed: $time->unboxed,
            competitionId: $time->competition?->id->toString(),
            competitionRoundId: $time->competitionRound?->id->toString(),
            piecesPlaced: $time->piecesPlaced,
            qualified: $time->qualified,
            suspicious: $time->suspicious,
            finishedLaterSeconds: $time->finishedLaterSeconds,
            createdVia: $time->createdVia,
            predictable: $time->predictable,
            predictionMethod: $time->predictionMethod,
            predictedSeconds: $time->predictedSeconds,
            predictedRangeLowSeconds: $time->predictedRangeLowSeconds,
            predictedRangeHighSeconds: $time->predictedRangeHighSeconds,
            predictedAttemptNumber: $time->predictedAttemptNumber,
            predictionLastTimeSeconds: $time->predictionLastTimeSeconds,
            predictionSource: $time->predictionSource,
            predictionComputedAt: $time->predictionComputedAt,
            predictionModelVersion: $time->predictionModelVersion,
        );
    }

    public function group(): null|PuzzlersGroup
    {
        if ($this->team === null || $this->team['puzzlers'] === []) {
            return null;
        }

        return new PuzzlersGroup(
            teamId: $this->team['team_id'],
            puzzlers: array_map(
                static fn (array $puzzler): Puzzler => new Puzzler(
                    playerId: $puzzler['player_id'],
                    playerName: $puzzler['player_name'],
                    playerCode: null,
                    playerCountry: null,
                    isPrivate: false,
                ),
                $this->team['puzzlers'],
            ),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'seconds_to_solve' => $this->secondsToSolve,
            'player_id' => $this->playerId,
            'puzzle_id' => $this->puzzleId,
            'puzzle_name' => $this->puzzleName,
            'tracked_at' => $this->trackedAt->format(self::DATE_FORMAT),
            'verified' => $this->verified,
            'team' => $this->team,
            'puzzling_team_id' => $this->puzzlingTeamId,
            'finished_at' => $this->finishedAt?->format(self::DATE_FORMAT),
            'comment' => $this->comment,
            'finished_puzzle_photo' => $this->finishedPuzzlePhoto,
            'first_attempt' => $this->firstAttempt,
            'unboxed' => $this->unboxed,
            'competition_id' => $this->competitionId,
            'competition_round_id' => $this->competitionRoundId,
            'pieces_placed' => $this->piecesPlaced,
            'qualified' => $this->qualified,
            'suspicious' => $this->suspicious,
            'finished_later_seconds' => $this->finishedLaterSeconds,
            'created_via' => $this->createdVia?->value,
            'predictable' => $this->predictable,
            'prediction_method' => $this->predictionMethod?->value,
            'predicted_seconds' => $this->predictedSeconds,
            'predicted_range_low_seconds' => $this->predictedRangeLowSeconds,
            'predicted_range_high_seconds' => $this->predictedRangeHighSeconds,
            'predicted_attempt_number' => $this->predictedAttemptNumber,
            'prediction_last_time_seconds' => $this->predictionLastTimeSeconds,
            'prediction_source' => $this->predictionSource?->value,
            'prediction_computed_at' => $this->predictionComputedAt?->format(self::DATE_FORMAT),
            'prediction_model_version' => $this->predictionModelVersion,
        ];
    }

    /**
     * @param array<string, mixed> $data what toArray() stored
     */
    public static function fromArray(array $data): self
    {
        /** @var array{id: string, seconds_to_solve: null|int, player_id: string, puzzle_id: string, puzzle_name: string, tracked_at: string, verified: bool, team: null|array{team_id: null|string, puzzlers: list<array{player_id: null|string, player_name: null|string}>}, puzzling_team_id: null|string, finished_at: null|string, comment: null|string, finished_puzzle_photo: null|string, first_attempt: bool, unboxed: bool, competition_id: null|string, competition_round_id: null|string, pieces_placed: null|int, qualified: null|bool, suspicious: bool, finished_later_seconds: null|int, created_via: null|string, predictable: null|bool, prediction_method: null|string, predicted_seconds: null|int, predicted_range_low_seconds: null|int, predicted_range_high_seconds: null|int, predicted_attempt_number: null|int, prediction_last_time_seconds: null|int, prediction_source: null|string, prediction_computed_at: null|string, prediction_model_version: null|int} $data */

        return new self(
            id: $data['id'],
            secondsToSolve: $data['seconds_to_solve'],
            playerId: $data['player_id'],
            puzzleId: $data['puzzle_id'],
            puzzleName: $data['puzzle_name'],
            trackedAt: new DateTimeImmutable($data['tracked_at']),
            verified: $data['verified'],
            team: $data['team'],
            puzzlingTeamId: $data['puzzling_team_id'],
            finishedAt: $data['finished_at'] !== null ? new DateTimeImmutable($data['finished_at']) : null,
            comment: $data['comment'],
            finishedPuzzlePhoto: $data['finished_puzzle_photo'],
            firstAttempt: $data['first_attempt'],
            unboxed: $data['unboxed'],
            competitionId: $data['competition_id'],
            competitionRoundId: $data['competition_round_id'],
            piecesPlaced: $data['pieces_placed'],
            qualified: $data['qualified'],
            suspicious: $data['suspicious'],
            finishedLaterSeconds: $data['finished_later_seconds'],
            createdVia: $data['created_via'] !== null ? SolvingTimeSource::from($data['created_via']) : null,
            predictable: $data['predictable'],
            predictionMethod: $data['prediction_method'] !== null ? TimePredictionMethod::from($data['prediction_method']) : null,
            predictedSeconds: $data['predicted_seconds'],
            predictedRangeLowSeconds: $data['predicted_range_low_seconds'],
            predictedRangeHighSeconds: $data['predicted_range_high_seconds'],
            predictedAttemptNumber: $data['predicted_attempt_number'],
            predictionLastTimeSeconds: $data['prediction_last_time_seconds'],
            predictionSource: $data['prediction_source'] !== null ? TimePredictionSource::from($data['prediction_source']) : null,
            predictionComputedAt: $data['prediction_computed_at'] !== null ? new DateTimeImmutable($data['prediction_computed_at']) : null,
            predictionModelVersion: $data['prediction_model_version'],
        );
    }
}
