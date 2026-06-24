<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Security\ApiUser;
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
        assert($user instanceof ApiUser);

        $profile = $this->getPlayerProfile->byId($user->getPlayer()->id->toString());

        // Email is private. Personal Access Tokens grant full access to the owner's own
        // data, while third-party OAuth2 clients must be explicitly granted the
        // "email:read" scope (role ROLE_OAUTH2_EMAIL:READ) — otherwise email stays null.
        $canReadEmail = $this->security->isGranted('ROLE_PAT')
            || $this->security->isGranted('ROLE_OAUTH2_EMAIL:READ');

        return new CurrentUserResponse(
            id: $profile->playerId,
            name: $profile->playerName,
            email: $canReadEmail ? $profile->email : null,
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
