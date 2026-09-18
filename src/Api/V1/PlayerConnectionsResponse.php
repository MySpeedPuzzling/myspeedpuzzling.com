<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;
use ApiPlatform\OpenApi\Model\Response as OpenApiResponse;
use SpeedPuzzling\Web\Results\PlayerConnection;

/**
 * The token owner's favorites link in either direction: the players they
 * follow (favorites) and the players following them (followers).
 */
#[ApiResource(
    shortName: 'PlayerConnections',
    operations: [
        new Get(
            uriTemplate: '/v1/me/favorites',
            name: 'my_favorites',
            openapi: new OpenApiOperation(
                tags: ['My Profile'],
                summary: 'Players you follow',
                description: 'The players on your favorites list, by name. is_mutual tells whether the player has you '
                    . 'in their favorites too. A private player is masked as everywhere in the API: name, avatar and '
                    . 'country are null, is_private is true - clients render the "Secret puzzler #CODE" label from '
                    . 'is_private and code. Private players come last, ordered by code.',
                responses: [
                    '401' => new OpenApiResponse(description: 'Missing, invalid or expired token.'),
                    '403' => new OpenApiResponse(description: 'The token was not granted the profile:read scope, or has no player behind it.'),
                ],
            ),
            security: "is_granted('ROLE_PAT') or is_granted('ROLE_OAUTH2_PROFILE:READ')",
            provider: MyFavoritesResponseProvider::class,
        ),
        new Get(
            uriTemplate: '/v1/me/followers',
            name: 'my_followers',
            openapi: new OpenApiOperation(
                tags: ['My Profile'],
                summary: 'Players following you',
                description: 'The players who have you on their favorites list, by name. is_mutual tells whether you '
                    . 'follow the player back. A follower with a private profile is counted and listed, but masked as '
                    . 'everywhere in the API: name, avatar and country are null, is_private is true - their favorites '
                    . 'list is hidden on the website, so the API does not identify them either. Private players come '
                    . 'last, ordered by code.',
                responses: [
                    '401' => new OpenApiResponse(description: 'Missing, invalid or expired token.'),
                    '403' => new OpenApiResponse(description: 'The token was not granted the profile:read scope, or has no player behind it.'),
                ],
            ),
            security: "is_granted('ROLE_PAT') or is_granted('ROLE_OAUTH2_PROFILE:READ')",
            provider: MyFollowersResponseProvider::class,
        ),
    ],
)]
final class PlayerConnectionsResponse
{
    /** @var list<PlayerConnectionResponse> */
    public array $items;

    /**
     * @param list<PlayerConnectionResponse> $items
     */
    public function __construct(
        public string $playerId,
        public int $count,
        array $items,
    ) {
        $this->items = $items;
    }

    /**
     * @param list<PlayerConnection> $connections
     */
    public static function fromConnections(string $playerId, array $connections): self
    {
        return new self(
            playerId: $playerId,
            count: count($connections),
            items: array_map(PlayerConnectionResponse::fromConnection(...), $connections),
        );
    }
}
