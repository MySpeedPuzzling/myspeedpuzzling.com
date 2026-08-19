<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Api\V1;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Get;
use ApiPlatform\OpenApi\Model\Operation as OpenApiOperation;

/**
 * The puzzle library page as the API (PuzzleLibraryController): every
 * section of a player's library with its count and the owner's visibility
 * setting, and the collections with their item counts. What the token may
 * not see is zeroed, never an error - the visibility says why.
 */
#[ApiResource(
    shortName: 'Library',
    operations: [
        new Get(
            uriTemplate: '/v1/me/library',
            name: 'my_library',
            openapi: new OpenApiOperation(
                tags: ['Puzzle Library'],
                summary: 'Your puzzle library summary',
                description: 'The sections of your puzzle library as the library page shows them: your collections '
                    . '(the system collection "default" and your custom ones, each with its item count), and the counts '
                    . 'of your unsolved puzzles (puzzles in your collections you have not solved yet, borrowed ones '
                    . 'included), wishlist, lend/borrow list (lent out / borrowed), sell/swap list and solved puzzles. '
                    . 'Each section carries your visibility setting for it ("public" or "private") - the sell/swap '
                    . 'list is always public. Your own library is always complete.',
            ),
            security: "is_granted('ROLE_PAT') or is_granted('ROLE_OAUTH2_COLLECTIONS:READ')",
            provider: MyLibraryResponseProvider::class,
        ),
        new Get(
            uriTemplate: '/v1/players/{playerId}/library',
            name: 'player_library',
            openapi: new OpenApiOperation(
                tags: ['Puzzle Library'],
                summary: 'Puzzle library summary of a player',
                description: 'The sections of a player\'s puzzle library as the website shows them to you: public '
                    . 'collections (the system collection "default" when the player made it public, custom collections '
                    . 'that are public) with their item counts, and the counts of the unsolved puzzles, wishlist, '
                    . 'lend/borrow list, sell/swap list (always public) and solved puzzles. A section the player keeps '
                    . 'private reports count 0 and visibility "private"; a private profile reports everything as 0 / '
                    . '"private" and no collections. The player behind an authorization-code token asking for their '
                    . 'own id gets the complete library, exactly as under /me.',
            ),
            security: "is_granted('ROLE_OAUTH2_COLLECTIONS:READ')",
            provider: PlayerLibraryResponseProvider::class,
        ),
    ],
)]
final class LibraryResponse
{
    /** @var array<LibraryCollectionResponse> */
    public array $collections;

    /**
     * @param array<LibraryCollectionResponse> $collections
     */
    public function __construct(
        public string $player_id,
        array $collections,
        public LibrarySectionResponse $unsolved,
        public LibrarySectionResponse $wishlist,
        public LibraryLendBorrowSectionResponse $lend_borrow,
        public LibrarySellSwapSectionResponse $sell_swap,
        public LibrarySectionResponse $solved,
    ) {
        $this->collections = $collections;
    }
}
