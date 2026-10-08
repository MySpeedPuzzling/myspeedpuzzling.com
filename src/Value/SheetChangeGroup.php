<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Changes the server applies all together or not at all - one organiser action (typing a pair into the new row, a
 * paste, a move), with the page's own id to answer it by.
 */
readonly final class SheetChangeGroup
{
    /**
     * @param non-empty-list<SheetChange> $changes
     */
    public function __construct(
        public string $id,
        public array $changes,
    ) {
    }

    /**
     * The group in the wire format (SheetChange::toArray()) - what a change set's receipt keeps of the request.
     *
     * @return array{id: string, changes: list<array<string, null|string|int>>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'changes' => array_map(static fn (SheetChange $change): array => $change->toArray(), $this->changes),
        ];
    }
}
