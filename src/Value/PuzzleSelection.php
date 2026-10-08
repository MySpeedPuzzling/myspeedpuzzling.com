<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Request;

/**
 * The puzzles a player selected on one of their own list pages - a collection, the wishlist, sell/swap, unsolved or
 * lend/borrow (docs/features/collections/bulk-actions.md). Read from the POST body (`puzzleIds[]`), never the URL:
 * the largest lists hold well over a thousand puzzles.
 */
readonly final class PuzzleSelection
{
    public const string CSRF_TOKEN_ID = 'collection_selection';

    // A sanity limit above the largest collection on production (1,347 puzzles, 2026-10-08)
    public const int MAX_PUZZLES = 2000;

    /**
     * @param list<string> $puzzleIds
     */
    private function __construct(
        public array $puzzleIds,
    ) {
    }

    public static function fromRequest(Request $request): self
    {
        $puzzleIds = [];

        foreach ($request->request->all('puzzleIds') as $puzzleId) {
            if (is_string($puzzleId) && Uuid::isValid($puzzleId)) {
                $puzzleIds[strtolower($puzzleId)] = true;
            }
        }

        return new self(array_slice(array_keys($puzzleIds), 0, self::MAX_PUZZLES));
    }
}
