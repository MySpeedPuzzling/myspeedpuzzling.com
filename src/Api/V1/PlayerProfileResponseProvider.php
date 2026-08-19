<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProviderInterface;
use SpeedPuzzling\Web\Query\GetPlayerProfile;
use SpeedPuzzling\Web\Services\Api\ApiTokenOwner;
use SpeedPuzzling\Web\Services\Api\ProfileInsightsResponseFactory;

/**
 * GET /api/v1/players/{playerId} - the profile page as the API, with its
 * gates (templates/player_profile.html.twig):
 *
 *   - a private profile is masked for everyone but the player behind the token
 *     (a machine token is never that player) - no insight query is run then;
 *   - rating and skill are hidden when the player opted out of rankings;
 *   - skill is Puzzle Insights and therefore members-only for the *viewer*:
 *     the token owner must be a member (ApiTokenOwner), whoever the profile
 *     belongs to - exactly the membership check the profile page makes on the
 *     logged-in visitor.
 *
 * @implements ProviderInterface<PlayerProfileResponse>
 */
final readonly class PlayerProfileResponseProvider implements ProviderInterface
{
    public function __construct(
        private GetPlayerProfile $getPlayerProfile,
        private ApiTokenOwner $tokenOwner,
        private ProfileInsightsResponseFactory $profileInsights,
    ) {
    }

    public function provide(Operation $operation, array $uriVariables = [], array $context = []): PlayerProfileResponse
    {
        /** @var string $playerId */
        $playerId = $uriVariables['playerId'];

        // Validates the uuid and throws PlayerNotFound (404) for an unknown one
        $profile = $this->getPlayerProfile->byId($playerId);

        $isOwnProfile = $this->tokenOwner->profile()?->playerId === $profile->playerId;

        if ($profile->isPrivate && $isOwnProfile === false) {
            return PlayerProfileResponse::masked($profile);
        }

        $showsRanking = $profile->rankingOptedOut === false;

        return new PlayerProfileResponse(
            id: $profile->playerId,
            name: $profile->playerName,
            code: $profile->code,
            avatar: $profile->avatar,
            country: $profile->country,
            city: $profile->city,
            bio: $profile->bio,
            facebook: $profile->facebook,
            instagram: $profile->instagram,
            isPrivate: $profile->isPrivate,
            hasActiveMembership: $profile->activeMembership,
            rating: $showsRanking ? $this->profileInsights->rating($profile->playerId) : null,
            skill: $showsRanking && $this->tokenOwner->isMember() ? $this->profileInsights->skill($profile->playerId) : null,
            badges: $this->profileInsights->badges($profile->playerId),
        );
    }
}
