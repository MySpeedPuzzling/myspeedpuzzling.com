<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What the add-time form offers when a player says "I puzzled with somebody": the pairs/teams they
 * belong to and the people those are made of, both ordered by how much and how recently they
 * puzzled together.
 */
readonly final class CoPuzzlerSuggestions
{
    public function __construct(
        /** @var list<TeamSuggestion> */
        public array $teams,
        /** @var list<PersonSuggestion> */
        public array $people,
    ) {
    }
}
