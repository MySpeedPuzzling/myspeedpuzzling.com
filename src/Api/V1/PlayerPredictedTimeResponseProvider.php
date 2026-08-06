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
final readonly class PlayerPredictedTimeResponseProvider implements ProviderInterface
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

        $requesterId = $user->getPlayer()->id->toString();

        /** @var string $playerId */
        $playerId = $uriVariables['playerId'];

        /** @var string $puzzleId */
        $puzzleId = $uriVariables['puzzleId'];

        $requesterProfile = $this->getPlayerProfile->byId($requesterId);

        return $this->predictedTimeResponseFactory->build(
            targetPlayerId: $playerId,
            puzzleId: $puzzleId,
            requesterHasActiveMembership: $requesterProfile->activeMembership,
            requesterIsTarget: $requesterId === $playerId,
        );
    }
}
