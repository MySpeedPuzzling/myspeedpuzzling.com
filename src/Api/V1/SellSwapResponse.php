<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;

/**
 * A player's sell/swap list (the website's sell-swap page), newest first.
 * The list is public for everyone on the website; only a private profile
 * hides it.
 */
#[ApiResource(
    shortName: 'SellSwap',
    operations: [
        new Get(
            uriTemplate: '/v1/me/sell-swap',
            name: 'my_sell_swap',
            openapi: new OpenApiOperation(
                tags: ['Puzzle Library'],
                summary: 'Your sell/swap list',
                description: 'The puzzles you offer for sale or swap, newest first: listing type, price in your '
                    . 'list-wide currency, condition, comment, whether the offer is reserved and whether it is '
                    . 'published on the marketplace. Every item carries the puzzle\'s public statistics, its difficulty '
                    . '(members only), your own time prediction (members who have not opted out; PAT or results:read) '
                    . 'and your own solves of the puzzle (PAT or results:read) - null when not available to this token.',
            ),
            security: "is_granted('ROLE_PAT') or is_granted('ROLE_OAUTH2_COLLECTIONS:READ')",
            provider: MySellSwapResponseProvider::class,
        ),
        new Get(
            uriTemplate: '/v1/players/{playerId}/sell-swap',
            name: 'player_sell_swap',
            openapi: new OpenApiOperation(
                tags: ['Puzzle Library'],
                summary: 'Sell/swap list of a player',
                description: 'The puzzles a player offers for sale or swap, newest first - the list is public on the '
                    . 'website, so it is returned for every player except a private profile (count 0 and no items; '
                    . 'the player behind an authorization-code token always sees their own). Who an offer is reserved '
                    . 'for is not exposed. Every item carries the puzzle\'s public statistics, its difficulty (the '
                    . 'token owner is a member), the token owner\'s own time prediction (member, not opted out, '
                    . 'results:read) and the seller\'s solves of the puzzle (results:read) - null when not available '
                    . 'to this token.',
            ),
            security: "is_granted('ROLE_OAUTH2_COLLECTIONS:READ')",
            provider: PlayerSellSwapResponseProvider::class,
        ),
    ],
)]
final class SellSwapResponse
{
    /** @var array<SellSwapItemResponse> */
    public array $items;

    /**
     * @param array<SellSwapItemResponse> $items
     */
    public function __construct(
        public string $player_id,
        public int $count,
        array $items,
    ) {
        $this->items = $items;
    }
}
