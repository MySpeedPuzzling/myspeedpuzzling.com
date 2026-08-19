<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Api;

use SpeedPuzzling\Web\Api\V1\PlayerSolvesResponse;
use SpeedPuzzling\Web\Api\V1\PuzzleDifficultyResponse;
use SpeedPuzzling\Web\Api\V1\PuzzleManufacturerResponse;
use SpeedPuzzling\Web\Api\V1\PuzzleResponse;
use SpeedPuzzling\Web\Api\V1\PuzzleStatisticsResponse;
use SpeedPuzzling\Web\Api\V1\TimePredictionResponse;
use SpeedPuzzling\Web\Query\GetPlayerPredictions;
use SpeedPuzzling\Web\Query\GetPlayerPuzzleSolves;
use SpeedPuzzling\Web\Query\GetPuzzleDifficulty;
use SpeedPuzzling\Web\Query\GetPuzzleStatistics;
use SpeedPuzzling\Web\Results\PlayerPuzzleSolves;
use SpeedPuzzling\Web\Results\PuzzleOverview;
use SpeedPuzzling\Web\Results\PuzzleStatisticsResult;

/**
 * Builds the public API's puzzle cards for the calling token, at a fixed
 * query cost: whatever the list size, one batch query for the community
 * statistics (GetPuzzleStatistics::forPuzzleList) and at most one per insight
 * object - difficulty (GetPuzzleDifficulty::forPuzzleList), the owner's
 * predictions (GetPlayerPredictions::forPuzzles, itself at most 4 queries) and
 * the owner's solves (GetPlayerPuzzleSolves::forPuzzles) - each of the latter
 * only when the token is entitled to the object at all. What the token is not
 * entitled to is null on every card, never an error (plan §0 N1/N3).
 *
 * Gates (docs/features/api/README.md, Members-Exclusive Data):
 *   difficulty  - token owner is a member
 *   prediction  - owner is a member, has not opted out of time predictions,
 *                 and the token may read results (PAT or results:read)
 *   solves      - there is an owner and the token may read results
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

        $profile = $this->tokenOwner->profile();
        $isMember = $this->tokenOwner->isMember();
        $canReadResults = $this->tokenOwner->canReadResults();

        // public, always; a puzzle nobody has solved has no row
        $statistics = $this->getPuzzleStatistics->forPuzzleList($puzzleIds);

        // null = not entitled (the object is null on every card); array = entitled,
        // keyed by puzzle id, with a default synthesised for ids the query did not return
        $difficulties = $isMember
            ? $this->getPuzzleDifficulty->forPuzzleList($puzzleIds)
            : null;

        $predictions = $profile !== null && $isMember && $profile->timePredictionsOptedOut === false && $canReadResults
            ? $this->getPlayerPredictions->forPuzzles($profile->playerId, $puzzleIds)
            : null;

        $solves = $profile !== null && $canReadResults
            ? $this->getPlayerPuzzleSolves->forPuzzles($profile->playerId, $puzzleIds)
            : null;

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
                statistics: PuzzleStatisticsResponse::fromResult($statistics[$puzzleId] ?? PuzzleStatisticsResult::empty($puzzleId)),
                difficulty: $difficulties === null
                    ? null
                    : (isset($difficulties[$puzzleId]) ? PuzzleDifficultyResponse::fromResult($difficulties[$puzzleId]) : PuzzleDifficultyResponse::insufficient()),
                prediction: $predictions === null
                    ? null
                    : TimePredictionResponse::fromResult($predictions[$puzzleId] ?? null),
                solves: $solves === null
                    ? null
                    : PlayerSolvesResponse::fromResult($solves[$puzzleId] ?? PlayerPuzzleSolves::empty($puzzleId)),
            );
        }

        return $cards;
    }

    public function card(PuzzleOverview $overview): PuzzleResponse
    {
        return $this->cards([$overview])[0];
    }
}
