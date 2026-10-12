<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * Where an entry sits on one Menu surface: a row (group + position, steps of 10), a search-only entry (found by the
 * Menu search, opens under its parent row) or not in the Menu at all - with the reason (README §7: every destination
 * is a Menu row or a search-only entry, or says why not).
 */
final readonly class MenuPlacement
{
    private function __construct(
        public null|MenuGroup $group,
        public null|int $position,
        public null|string $parent,
        public null|string $reason,
    ) {
    }

    public static function row(MenuGroup $group, int $position): self
    {
        return new self($group, $position, null, null);
    }

    /**
     * @param string $parent the id of the entry this one is found under (its page or the page holding its anchor)
     */
    public static function searchOnly(string $parent): self
    {
        return new self(null, null, $parent, null);
    }

    public static function notInMenu(string $reason): self
    {
        return new self(null, null, null, $reason);
    }

    public function isRow(): bool
    {
        return $this->group !== null;
    }

    public function isSearchOnly(): bool
    {
        return $this->parent !== null;
    }

    public function isNotInMenu(): bool
    {
        return $this->reason !== null;
    }
}
