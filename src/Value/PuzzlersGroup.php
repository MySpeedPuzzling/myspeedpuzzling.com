<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use LogicException;

readonly final class PuzzlersGroup
{
    public function __construct(
        public null|string $teamId,
        /** @var non-empty-array<Puzzler> */
        public array $puzzlers,
    ) {
    }

    /**
     * The people of an imported event result nobody has claimed yet - one anonymous slot each
     * (docs/features/event-results-import/README.md "Anonymous slots").
     *
     * @param non-empty-list<string> $slots "a:" + uuid
     */
    public static function anonymous(array $slots): self
    {
        foreach ($slots as $slot) {
            if (!str_starts_with($slot, TeamComposition::ANONYMOUS_PREFIX)) {
                throw new LogicException(sprintf('An anonymous slot starts with "%s", got "%s".', TeamComposition::ANONYMOUS_PREFIX, $slot));
            }
        }

        return new self(
            teamId: null,
            puzzlers: array_map(
                static fn(string $slot): Puzzler => new Puzzler(null, null, null, null, false, anonymousSlot: $slot),
                $slots,
            ),
        );
    }
}
