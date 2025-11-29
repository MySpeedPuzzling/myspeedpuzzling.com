<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class UserPuzzleStatuses
{
    /**
     * @param list<string> $solved
     * @param list<string> $wishlist
     * @param list<string> $unsolved
     * @param list<string> $collection
     * @param list<string> $borrowed
     * @param list<string> $lent
     * @param list<string> $sellSwap
     * @param array<string, string> $lentPuzzleIds Mapping puzzleId -> lentPuzzleId (for owned lent puzzles)
     * @param array<string, string> $borrowedPuzzleIds Mapping puzzleId -> lentPuzzleId (for borrowed puzzles)
     * @param array<string, array<string, string>> $puzzleCollections Mapping puzzleId -> [collectionId => collectionName]
     */
    public function __construct(
        public array $solved,
        public array $wishlist,
        public array $unsolved,
        public array $collection,
        public array $borrowed,
        public array $lent,
        public array $sellSwap,
        public array $lentPuzzleIds = [],
        public array $borrowedPuzzleIds = [],
        public array $puzzleCollections = [],
    ) {
    }

    public static function empty(): self
    {
        return new self(
            solved: [],
            wishlist: [],
            unsolved: [],
            collection: [],
            borrowed: [],
            lent: [],
            sellSwap: [],
        );
    }

    public function isEmpty(): bool
    {
        return $this->solved === []
            && $this->wishlist === []
            && $this->unsolved === []
            && $this->collection === []
            && $this->borrowed === []
            && $this->lent === []
            && $this->sellSwap === [];
    }
}
