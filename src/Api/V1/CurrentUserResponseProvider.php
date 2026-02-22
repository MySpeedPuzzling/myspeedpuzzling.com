<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Security\OAuth2User;
use Symfony\Bundle\SecurityBundle\Security;

/**
 * @implements ProviderInterface<CurrentUserResponse>
 */
final readonly class CurrentUserResponseProvider implements ProviderInterface
{
    public function __construct(
        private Security $security,
        private GetPlayerProfile $getPlayerProfile,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): CurrentUserResponse
    {
        $user = $this->security->getUser();
        assert($user instanceof OAuth2User);

        $profile = $this->getPlayerProfile->byId($user->player->id->toString());

        return new CurrentUserResponse(
            id: $profile->playerId,
            name: $profile->playerName,
            code: $profile->code,
            avatar: $profile->avatar,
            country: $profile->country,
            city: $profile->city,
            bio: $profile->bio,
            facebook: $profile->facebook,
            instagram: $profile->instagram,
            is_private: $profile->isPrivate,
            has_active_membership: $profile->activeMembership,
        );
    }
}
