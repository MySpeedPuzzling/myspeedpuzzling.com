<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Api;

use SpeedPuzzling\Web\Api\V1\PuzzleManufacturerResponse;
use SpeedPuzzling\Web\Api\V1\PuzzleResponse;
use SpeedPuzzling\Web\Query\GetPlayerPredictions;
use SpeedPuzzling\Web\Query\GetPlayerPuzzleSolves;
use SpeedPuzzling\Web\Query\GetPuzzleDifficulty;
use SpeedPuzzling\Web\Query\GetPuzzleStatistics;
use SpeedPuzzling\Web\Results\PuzzleOverview;

/**
 * Builds the public API's per-puzzle insight objects for the calling token,
 * at a fixed query cost: whatever the list size, one batch query for the
 * community statistics (GetPuzzleStatistics::forPuzzleList) and at most one
 * per insight object - difficulty (GetPuzzleDifficulty::forPuzzleList),
 * predictions (GetPlayerPredictions::forPuzzles, itself at most 4 queries) and
 * solves (GetPlayerPuzzleSolves::forPuzzles) - each of the latter only when
 * the token is entitled to the object at all. What the token is not entitled
 * to is null on every item, never an error (plan §0 N1/N3). One implementation
 * serves the puzzle cards (/puzzles), collection items and result lists.
 *
 * Gates (docs/features/api/README.md, Members-Exclusive Data):
 *   statistics  - public, always
 *   difficulty  - token owner is a member
 *   prediction  - only where the endpoint is self-only (includePrediction),
 *                 and the owner is a member, has not opted out of time
 *                 predictions, and the token may read results (PAT or results:read)
 *   solves      - a player whose solves to show was given (the token owner on
 *                 /me and /puzzles, the collection owner on /players/{id}/…)
 *                 and the token may read results
 */
final readonly class PuzzleResponseFactory
{
    public function __construct(
        private ApiTokenOwner $tokenOwner,
        private GetPuzzleStatistics $getPuzzleStatistics,
        private GetPuzzleDifficulty $getPuzzleDifficulty,
        private GetPlayerPredictions $getPlayerPredictions,
        private GetPlayerPuzzleSolves $getPlayerPuzzleSolves,
    ) {
    }

    /**
     * The insight objects for a list of puzzles, gated for the calling token.
     * Duplicate ids are fine (a result list repeats a puzzle per solve); an
     * empty list costs no query at all.
     *
     * @param array<string> $puzzleIds
     * @param null|string $solvesOfPlayerId whose solves the "solves" object shows; null = the endpoint has no solves object
     * @param bool $includePrediction whether the endpoint carries the self-only "prediction" object at all
     */
    public function insightsFor(array $puzzleIds, null|string $solvesOfPlayerId, bool $includePrediction): PuzzleInsightsBatch
    {
        $puzzleIds = array_values(array_unique($puzzleIds));

        if ($puzzleIds === []) {
            return PuzzleInsightsBatch::empty();
        }

        $isMember = $this->tokenOwner->isMember();
        $canReadResults = $this->tokenOwner->canReadResults();

        $predictionsOf = null;

        if ($includePrediction && $isMember && $canReadResults) {
            $profile = $this->tokenOwner->profile();

            if ($profile !== null && $profile->timePredictionsOptedOut === false) {
                $predictionsOf = $profile->playerId;
            }
        }

        return new PuzzleInsightsBatch(
            // public, always; a puzzle nobody has solved has no row
            statistics: $this->getPuzzleStatistics->forPuzzleList($puzzleIds),
            difficulties: $isMember
                ? $this->getPuzzleDifficulty->forPuzzleList($puzzleIds)
                : null,
            predictions: $predictionsOf !== null
                ? $this->getPlayerPredictions->forPuzzles($predictionsOf, $puzzleIds)
                : null,
            solves: $solvesOfPlayerId !== null && $canReadResults
                ? $this->getPlayerPuzzleSolves->forPuzzles($solvesOfPlayerId, $puzzleIds)
                : null,
        );
    }

    /**
     * @param list<PuzzleOverview> $overviews
     *
     * @return list<PuzzleResponse> in the same order
     */
    public function cards(array $overviews): array
    {
        if ($overviews === []) {
            return [];
        }

        $puzzleIds = array_map(static fn (PuzzleOverview $overview): string => $overview->puzzleId, $overviews);

        $insights = $this->insightsFor(
            $puzzleIds,
            solvesOfPlayerId: $this->tokenOwner->profile()?->playerId,
            includePrediction: true,
        );

        $cards = [];

        foreach ($overviews as $overview) {
            $puzzleId = $overview->puzzleId;

            $cards[] = new PuzzleResponse(
                id: $puzzleId,
                name: $overview->puzzleName,
                alternative_name: $overview->puzzleAlternativeName,
                manufacturer: new PuzzleManufacturerResponse(
                    id: $overview->manufacturerId,
                    name: $overview->manufacturerName,
                ),
                pieces_count: $overview->piecesCount,
                image: $overview->puzzleImage,
                ean: $overview->puzzleEan,
                identification_number: $overview->puzzleIdentificationNumber,
                is_available: $overview->isAvailable,
                is_approved: $overview->puzzleApproved,
                statistics: $insights->statistics($puzzleId),
                difficulty: $insights->difficulty($puzzleId),
                prediction: $insights->prediction($puzzleId),
                solves: $insights->solves($puzzleId),
            );
        }

        return $cards;
    }

    public function card(PuzzleOverview $overview): PuzzleResponse
    {
        return $this->cards([$overview])[0];
    }
}
