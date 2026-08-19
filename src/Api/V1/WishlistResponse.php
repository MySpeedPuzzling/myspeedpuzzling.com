<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;

/**
 * A player's wishlist (the website's wish-list page), newest first.
 */
#[ApiResource(
    shortName: 'Wishlist',
    operations: [
        new Get(
            uriTemplate: '/v1/me/wishlist',
            name: 'my_wishlist',
            openapi: new OpenApiOperation(
                tags: ['Puzzle Library'],
                summary: 'Your wishlist',
                description: 'The puzzles on your wishlist, newest first. Every item carries the puzzle\'s public '
                    . 'statistics, its difficulty (members only), your own time prediction (members who have not opted '
                    . 'out; PAT or results:read) and your own solves of the puzzle (PAT or results:read) - null when '
                    . 'not available to this token.',
            ),
            security: "is_granted('ROLE_PAT') or is_granted('ROLE_OAUTH2_COLLECTIONS:READ')",
            provider: MyWishlistResponseProvider::class,
        ),
        new Get(
            uriTemplate: '/v1/players/{playerId}/wishlist',
            name: 'player_wishlist',
            openapi: new OpenApiOperation(
                tags: ['Puzzle Library'],
                summary: 'Wishlist of a player',
                description: 'The puzzles on a player\'s wishlist, newest first - when the player made the wishlist '
                    . 'public (otherwise, and for a private profile, count 0 and no items; the player behind an '
                    . 'authorization-code token sees their own wishlist in full). Every item carries the puzzle\'s '
                    . 'public statistics, its difficulty (the token owner is a member), the token owner\'s own time '
                    . 'prediction (what the website shows a visitor; member, not opted out, results:read) and the '
                    . 'list owner\'s solves of the puzzle (results:read) - null when not available to this token.',
            ),
            security: "is_granted('ROLE_OAUTH2_COLLECTIONS:READ')",
            provider: PlayerWishlistResponseProvider::class,
        ),
    ],
)]
final class WishlistResponse
{
    /** @var array<WishlistItemResponse> */
    public array $items;

    /**
     * @param array<WishlistItemResponse> $items
     */
    public function __construct(
        public string $playerId,
        public int $count,
        array $items,
    ) {
        $this->items = $items;
    }
}
