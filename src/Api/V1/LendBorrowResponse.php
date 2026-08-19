<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;

/**
 * A player's lend/borrow list (the website's lend-borrow page): the puzzles
 * they lent out (direction "lent") followed by the puzzles they are
 * borrowing (direction "borrowed"), each group newest first.
 */
#[ApiResource(
    shortName: 'LendBorrow',
    operations: [
        new Get(
            uriTemplate: '/v1/me/lend-borrow',
            name: 'my_lend_borrow',
            openapi: new OpenApiOperation(
                tags: ['Puzzle Library'],
                summary: 'Your lend/borrow list',
                description: 'The puzzles you lent out (direction "lent", counterparty = the current holder - a '
                    . 'registered player with player_id, or a person entered by name) followed by the puzzles you are '
                    . 'borrowing (direction "borrowed", counterparty = the owner), each group newest first, with the '
                    . 'lend date and your note. Every item carries the puzzle\'s public statistics, its difficulty '
                    . '(members only), your own time prediction (members who have not opted out; PAT or results:read) '
                    . 'and your own solves of the puzzle (PAT or results:read) - null when not available to this token.',
            ),
            security: "is_granted('ROLE_PAT') or is_granted('ROLE_OAUTH2_COLLECTIONS:READ')",
            provider: MyLendBorrowResponseProvider::class,
        ),
        new Get(
            uriTemplate: '/v1/players/{playerId}/lend-borrow',
            name: 'player_lend_borrow',
            openapi: new OpenApiOperation(
                tags: ['Puzzle Library'],
                summary: 'Lend/borrow list of a player',
                description: 'The puzzles a player lent out and is borrowing - when the player made the list public '
                    . '(otherwise, and for a private profile, count 0 and no items; the player behind an '
                    . 'authorization-code token sees their own list in full). The counterparty is what the website '
                    . 'shows on the list: the other player\'s id and display name, or the free-text name. Every item '
                    . 'carries the puzzle\'s public statistics, its difficulty (the token owner is a member), the token '
                    . 'owner\'s own time prediction (member, not opted out, results:read) and the list owner\'s solves '
                    . 'of the puzzle (results:read) - null when not available to this token.',
            ),
            security: "is_granted('ROLE_OAUTH2_COLLECTIONS:READ')",
            provider: PlayerLendBorrowResponseProvider::class,
        ),
    ],
)]
final class LendBorrowResponse
{
    /** @var array<LentPuzzleResponse> */
    public array $items;

    /**
     * @param array<LentPuzzleResponse> $items
     */
    public function __construct(
        public string $playerId,
        public int $count,
        array $items,
    ) {
        $this->items = $items;
    }
}
