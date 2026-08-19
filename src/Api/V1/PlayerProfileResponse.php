<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\Metadata\Link;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use SpeedPuzzling\Web\Results\PlayerProfile;

/**
 * GET /api/v1/players/{playerId} - a player's public profile as the profile
 * page shows it, with the same three insight blocks as GET /me. The shape is
 * the GET /me one without email, membership_ends_at and the opt-out flags.
 */
#[ApiResource(
    shortName: 'PlayerProfile',
    operations: [
        new Get(
            uriTemplate: '/v1/players/{playerId}',
            // Declared explicitly: the response carries an "id" property, which API
            // Platform would otherwise take as the identifier and name the path
            // variable "id" - while the route (and the provider) say "playerId".
            uriVariables: ['playerId' => new Link(fromClass: self::class, identifiers: ['id'])],
            openapi: new OpenApiOperation(
                tags: ['Players'],
                summary: 'Public profile of a player',
                description: 'The player\'s profile page as the API: public profile fields plus the three insight blocks. '
                    . 'A private profile is masked exactly as on the website unless the token belongs to that player: '
                    . 'name, avatar, country, city, bio, facebook and instagram are null, is_private is true, id, code and '
                    . 'has_active_membership stay, rating and skill are null and badges is empty - clients render the '
                    . '"Secret puzzler #CODE" label from is_private and code. '
                    . '"rating" is the MSP Rating per piece count (points = the rating the website displays, rank and '
                    . 'total_players among the ranked players); null when the player opted out of rankings, an empty list '
                    . 'when not ranked yet. "skill" is the skill tier per piece count (enthusiast, apprentice, proficient, '
                    . 'advanced, expert, master, legend) - Puzzle Insights, members-only exactly as on the website: the '
                    . 'token owner (PAT owner or the player behind an authorization-code token) must be a member, so a '
                    . 'client_credentials token always gets null; also null when the player opted out of rankings, an empty '
                    . 'list when there is no tier yet. "badges" lists the badge tokens the player earned. '
                    . 'Unknown or malformed id: 404.',
                responses: [
                    '401' => new OpenApiResponse(description: 'Missing, invalid or expired token.'),
                    '403' => new OpenApiResponse(description: 'The token was not granted the profile:read scope.'),
                    '404' => new OpenApiResponse(description: 'Unknown or malformed player id.'),
                ],
            ),
            security: "is_granted('ROLE_OAUTH2_PROFILE:READ')",
            provider: PlayerProfileResponseProvider::class,
        ),
    ],
)]
final class PlayerProfileResponse
{
    /**
     * @param null|list<PlayerRatingResponse> $rating null when the profile is masked or the player opted out of rankings
     * @param null|list<PlayerSkillResponse> $skill null unless the token owner is a member and the profile is neither masked nor opted out of rankings
     * @param list<string> $badges
     */
    public function __construct(
        public string $id,
        public null|string $name,
        public string $code,
        public null|string $avatar,
        public null|string $country,
        public null|string $city,
        public null|string $bio,
        public null|string $facebook,
        public null|string $instagram,
        public bool $isPrivate,
        public bool $hasActiveMembership,
        public null|array $rating,
        public null|array $skill,
        public array $badges,
    ) {
    }

    /**
     * A private profile seen by anyone but its owner - the website's "Secret
     * puzzler #CODE": nothing but the id, the code and the membership badge.
     */
    public static function masked(PlayerProfile $profile): self
    {
        return new self(
            id: $profile->playerId,
            name: null,
            code: $profile->code,
            avatar: null,
            country: null,
            city: null,
            bio: null,
            facebook: null,
            instagram: null,
            isPrivate: true,
            hasActiveMembership: $profile->activeMembership,
            rating: null,
            skill: null,
            badges: [],
        );
    }
}
