<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Entity\Collection;
use SpeedPuzzling\Web\Results\CollectionOverview;
use Symfony\Component\HttpFoundation\Request;

/**
 * The puzzles a player selected on one of their collection pages (docs/features/collections/bulk-actions.md) - read
 * from the POST body (`puzzleIds[]`), never the URL: the largest collections hold well over a thousand puzzles.
 */
readonly final class CollectionSelection
{
    public const string CSRF_TOKEN_ID = PuzzleSelection::CSRF_TOKEN_ID;

    /**
     * @param list<string> $puzzleIds
     */
    private function __construct(
        public array $puzzleIds,
        // The collection the page shows, null = the system collection
        public null|string $collectionId,
        public string $collectionName,
    ) {
    }

    /**
     * Null when the page's collection is not one of the player's own.
     *
     * @param array<CollectionOverview> $playerCollections
     */
    public static function fromRequest(
        Request $request,
        string $collectionId,
        array $playerCollections,
        string $systemCollectionName,
    ): null|self {
        $puzzleIds = PuzzleSelection::fromRequest($request)->puzzleIds;

        if ($collectionId === Collection::SYSTEM_ID) {
            return new self($puzzleIds, null, $systemCollectionName);
        }

        foreach ($playerCollections as $collection) {
            if ($collection->collectionId === $collectionId) {
                return new self($puzzleIds, $collectionId, $collection->name);
            }
        }

        return null;
    }

    public function routeCollectionId(): string
    {
        return $this->collectionId ?? Collection::SYSTEM_ID;
    }
}
