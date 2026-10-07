<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One line of the "find your table" list of the printed seating (SeatingPrintList::byName()): a person and the table
 * of their entry - a member of a pair/team points to the pair's/team's table.
 */
readonly final class SeatingListName
{
    public function __construct(
        public string $name,
        public null|string $country,
        public null|int $tableNumber,
        // The pair's/team's name; null for a person, or an unnamed pair/team (then $partners say who with)
        public null|string $teamName,
        /** @var list<string> the other members of the pair/team */
        public array $partners,
    ) {
    }
}
