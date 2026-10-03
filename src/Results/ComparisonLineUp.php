<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\ComparisonKind;
use SpeedPuzzling\Web\Value\ComparisonSubjectRef;

/**
 * The signed-in player's comparison line-ups as their own profile row carries them (GetPlayerProfile::byUserId(), no
 * query of its own): what the launcher pill, the "Add to / Remove from comparison" buttons and the team page need on
 * every page (docs/features/player-comparison.md). Who a subject is and whether the viewer may still see them is not
 * here - only for the few newest, as mini avatars.
 */
readonly final class ComparisonLineUp
{
    public const int RECENT_LIMIT = 3;

    public function __construct(
        /** @var list<ComparisonLineUpItem> every kind, oldest first */
        public array $items = [],
        /** @var list<ComparisonLineUpRecentSubject> newest first, never the owner, at most RECENT_LIMIT */
        public array $recent = [],
    ) {
    }

    /**
     * Subjects other than the owner, every kind - the launcher's number; it shows from 1.
     */
    public function count(): int
    {
        return count(array_filter($this->items, static fn (ComparisonLineUpItem $item): bool => $item->isSelf === false));
    }

    public function hasOthers(): bool
    {
        return $this->count() > 0;
    }

    /**
     * Every row of that line-up, the owner included - what the cap counts ("n / cap").
     */
    public function countForKind(ComparisonKind $kind): int
    {
        return count($this->itemsForKind($kind));
    }

    /**
     * @return list<ComparisonLineUpItem>
     */
    public function itemsForKind(ComparisonKind $kind): array
    {
        return array_values(array_filter($this->items, static fn (ComparisonLineUpItem $item): bool => $item->kind === $kind));
    }

    /**
     * @return list<ComparisonSubjectRef>
     */
    public function refsForKind(ComparisonKind $kind): array
    {
        return array_map(static fn (ComparisonLineUpItem $item): ComparisonSubjectRef => $item->ref, $this->itemsForKind($kind));
    }

    public function contains(ComparisonSubjectRef $ref): bool
    {
        return $this->rowIdOf($ref) !== null;
    }

    /**
     * The row to remove ("Remove from comparison") - null when the subject is not in any line-up.
     */
    public function rowIdOf(ComparisonSubjectRef $ref): null|string
    {
        foreach ($this->items as $item) {
            if ($item->ref->equals($ref)) {
                return $item->rowId;
            }
        }

        return null;
    }

    /**
     * @return list<ComparisonLineUpRecentSubject>
     */
    public function recent(): array
    {
        return $this->recent;
    }

    /**
     * The line-up something was added to last - the comparison opens on it. Null while nothing was ever added.
     */
    public function newestKind(): null|ComparisonKind
    {
        if ($this->items === []) {
            return null;
        }

        return $this->items[count($this->items) - 1]->kind;
    }
}
