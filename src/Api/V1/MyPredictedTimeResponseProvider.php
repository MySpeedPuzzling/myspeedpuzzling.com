<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Query\GetPlayerPrediction;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Query\GetPuzzleDifficulty;
use SpeedPuzzling\Web\Query\GetPuzzleOverview;
use SpeedPuzzling\Web\Security\ApiUser;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * Own predicted time for a puzzle - the API twin of the Puzzle Insights block on the
 * puzzle detail page, with the same gates (PuzzleDetailController): the token owner
 * must be a member, and the prediction also respects their own opt-out. There is
 * deliberately no /players/{id} variant: predictions are self-only on the website,
 * and one member's token must not become a proxy that serves a members-only feature
 * to everyone.
 *
 * @implements ProviderInterface<PredictedTimeResponse>
 */
final readonly class MyPredictedTimeResponseProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private GetPlayerProfile $getPlayerProfile,
        private GetPuzzleOverview $getPuzzleOverview,
        private GetPlayerPrediction $getPlayerPrediction,
        private GetPuzzleDifficulty $getPuzzleDifficulty,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PredictedTimeResponse
    {
        $user = $this->security->getUser();
        assert($user instanceof ApiUser);

        /** @var string $puzzleId */
        $puzzleId = $uriVariables['puzzleId'];

        // Throws PuzzleNotFound (404) for a missing or malformed id.
        $this->getPuzzleOverview->byId($puzzleId);

        $profile = $this->getPlayerProfile->byId($user->getPlayer()->id->toString());

        if ($profile->activeMembership === false) {
            return PredictedTimeResponse::membersOnly($puzzleId);
        }

        $difficulty = $this->getPuzzleDifficulty->byPuzzleId($puzzleId);

        $prediction = $profile->timePredictionsOptedOut
            ? null
            : $this->getPlayerPrediction->forPuzzle($profile->playerId, $puzzleId);

        return new PredictedTimeResponse(
            puzzle_id: $puzzleId,
            predicted_seconds: $prediction?->predictedSeconds,
            range_low_seconds: $prediction?->rangeLowSeconds,
            range_high_seconds: $prediction?->rangeHighSeconds,
            is_personalized: $prediction !== null && $prediction->isPersonalized,
            personal_solve_count: $prediction?->personalSolveCount,
            last_time_seconds: $prediction?->lastTimeSeconds,
            difficulty_score: $difficulty?->difficultyScore,
            difficulty_level: $difficulty?->difficultyTier?->toApiValue(),
            difficulty_confidence: $difficulty?->confidence->value,
        );
    }
}
