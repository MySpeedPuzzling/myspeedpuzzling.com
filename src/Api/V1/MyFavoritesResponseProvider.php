<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Query\GetPlayerConnections;
use SpeedPuzzling\Web\Security\ApiUser;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * GET /api/v1/me/favorites - the players the token owner follows.
 *
 * @implements ProviderInterface<PlayerConnectionsResponse>
 */
final readonly class MyFavoritesResponseProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private GetPlayerConnections $getPlayerConnections,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayerConnectionsResponse
    {
        $user = $this->security->getUser();
        assert($user instanceof ApiUser);

        $playerId = $user->getPlayer()->id->toString();

        return PlayerConnectionsResponse::fromConnections(
            $playerId,
            $this->getPlayerConnections->favoritesOf($playerId),
        );
    }
}
