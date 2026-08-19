<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;

/**
 * A player's unsolved puzzles (the website's unsolved-puzzles page): the
 * puzzles in any of their collections they have not solved yet, plus the
 * puzzles they are borrowing and have not solved yet - borrowed ones first,
 * each group newest first.
 */
#[ApiResource(
    shortName: 'UnsolvedPuzzles',
    operations: [
        new Get(
            uriTemplate: '/v1/me/unsolved-puzzles',
            name: 'my_unsolved_puzzles',
            openapi: new OpenApiOperation(
                tags: ['Puzzle Library'],
                summary: 'Your unsolved puzzles',
                description: 'The puzzles in your collections you have not solved yet (is_borrowed false, one entry '
                    . 'per puzzle whatever the number of collections it is in), plus the puzzles you are currently '
                    . 'borrowing and have not solved yet (is_borrowed true, listed first) - exactly the website\'s '
                    . 'unsolved-puzzles list. Every item carries the puzzle\'s public statistics, its difficulty '
                    . '(members only), your own time prediction (members who have not opted out; PAT or results:read) '
                    . 'and your own solves of the puzzle (PAT or results:read; zeros here by definition) - null when '
                    . 'not available to this token.',
            ),
            security: "is_granted('ROLE_PAT') or is_granted('ROLE_OAUTH2_COLLECTIONS:READ')",
            provider: MyUnsolvedPuzzlesResponseProvider::class,
        ),
        new Get(
            uriTemplate: '/v1/players/{playerId}/unsolved-puzzles',
            name: 'player_unsolved_puzzles',
            openapi: new OpenApiOperation(
                tags: ['Puzzle Library'],
                summary: 'Unsolved puzzles of a player',
                description: 'The puzzles in a player\'s collections and the puzzles they are borrowing that they have '
                    . 'not solved yet - when the player made the list public (otherwise, and for a private profile, '
                    . 'count 0 and no items; the player behind an authorization-code token sees their own list in '
                    . 'full). Every item carries the puzzle\'s public statistics, its difficulty (the token owner is a '
                    . 'member), the token owner\'s own time prediction (member, not opted out, results:read) and the '
                    . 'list owner\'s solves of the puzzle (results:read) - null when not available to this token.',
            ),
            security: "is_granted('ROLE_OAUTH2_COLLECTIONS:READ')",
            provider: PlayerUnsolvedPuzzlesResponseProvider::class,
        ),
    ],
)]
final class UnsolvedPuzzlesResponse
{
    /** @var array<UnsolvedPuzzleResponse> */
    public array $items;

    /**
     * @param array<UnsolvedPuzzleResponse> $items
     */
    public function __construct(
        public string $player_id,
        public int $count,
        array $items,
    ) {
        $this->items = $items;
    }
}
