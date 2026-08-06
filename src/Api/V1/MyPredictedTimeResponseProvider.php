<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Security\ApiUser;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<PredictedTimeResponse>
 */
final readonly class MyPredictedTimeResponseProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private GetPlayerProfile $getPlayerProfile,
        private PredictedTimeResponseFactory $predictedTimeResponseFactory,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PredictedTimeResponse
    {
        $user = $this->security->getUser();
        assert($user instanceof ApiUser);

        $playerId = $user->getPlayer()->id->toString();

        /** @var string $puzzleId */
        $puzzleId = $uriVariables['puzzleId'];

        $profile = $this->getPlayerProfile->byId($playerId);

        return $this->predictedTimeResponseFactory->build(
            targetPlayerId: $playerId,
            puzzleId: $puzzleId,
            requesterHasActiveMembership: $profile->activeMembership,
            requesterIsTarget: true,
        );
    }
}
