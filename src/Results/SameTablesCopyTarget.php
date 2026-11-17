<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A later round the source round's tables can be copied to (SameTablesCopy, competition toolkit W3) - what the confirm
 * shows, and the plan the copy writes.
 */
readonly final class SameTablesCopyTarget implements \JsonSerializable
{
    public function __construct(
        public string $roundId,
        public string $name,
        public int $entries,
        // Entrants who get the table they have in the source round
        public int $matched,
        // Entries whose table would change
        public int $changes,
        // SameTablesCopy::hash() - the entrants and how many of them have a table, as the confirm shows them
        public string $hash,
        /** @var array<string, int> entry ref => table */
        public array $tables,
    ) {
    }

    public function highestTable(): int
    {
        return $this->tables === [] ? 0 : max($this->tables);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'roundId' => $this->roundId,
            'name' => $this->name,
            'entries' => $this->entries,
            'matched' => $this->matched,
            'changes' => $this->changes,
            'hash' => $this->hash,
        ];
    }
}
