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
     */
    public function __construct(
        public array $solved,
        public array $wishlist,
        public array $unsolved,
        public array $collection,
        public array $borrowed,
        public array $lent,
        public array $sellSwap,
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
