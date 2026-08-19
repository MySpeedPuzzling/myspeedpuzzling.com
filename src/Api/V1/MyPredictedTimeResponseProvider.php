<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPlayerPrediction;
use SpeedPuzzling\Web\Query\GetPuzzleDifficulty;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Services\Api\ApiTokenOwner;

/**
 * Own predicted time for a puzzle - the API twin of the Puzzle Insights block on the
 * puzzle detail page, with the same gates (PuzzleDetailController): the token owner
 * must be a member, and the prediction also respects their own opt-out. There is
 * deliberately no /players/{id} variant: predictions are self-only on the website,
 * and one member's token must not become a proxy that serves a members-only feature
 * to everyone.
 *
 * The flat shape predates the insight objects of GET /v1/puzzles and
 * GET /v1/puzzles/{id} and stays as it is (no BC breaks); it is a projection of the
 * very same objects, built by the same code (TimePredictionResponse,
 * PuzzleDifficultyResponse) behind the same gate (ApiTokenOwner), so the two
 * surfaces cannot disagree.
 *
 * @implements ProviderInterface<PredictedTimeResponse>
 */
final readonly class MyPredictedTimeResponseProvider implements ProviderInterface
{
    public function __construct(
        private ApiTokenOwner $tokenOwner,
        private GetPuzzleOverview $getPuzzleOverview,
        private GetPlayerPrediction $getPlayerPrediction,
        private GetPuzzleDifficulty $getPuzzleDifficulty,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PredictedTimeResponse
    {
        /** @var string $puzzleId */
        $puzzleId = $uriVariables['puzzleId'];

        // Throws PuzzleNotFound (404) for a missing or malformed id.
        $this->getPuzzleOverview->byId($puzzleId);

        // /api/v1/me/* is reachable only with a player behind the token (access_control),
        // so the owner is never null here; the throw keeps that a 404, not a 500.
        $profile = $this->tokenOwner->profile() ?? throw new PlayerNotFound();

        if ($this->tokenOwner->isMember() === false) {
            return PredictedTimeResponse::membersOnly($puzzleId);
        }

        $difficulty = $this->getPuzzleDifficulty->byPuzzleId($puzzleId);

        $prediction = $profile->timePredictionsOptedOut
            ? null
            : $this->getPlayerPrediction->forPuzzle($profile->playerId, $puzzleId);

        return PredictedTimeResponse::fromInsights(
            $puzzleId,
            TimePredictionResponse::fromResult($prediction),
            $difficulty !== null ? PuzzleDifficultyResponse::fromResult($difficulty) : null,
        );
    }
}
