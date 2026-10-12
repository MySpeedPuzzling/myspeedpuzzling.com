<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * One group of the Menu as rendered (MenuBuilder): its rows, both surface orders (data-order-phone / data-order-dock -
 * MENU-02b sorts the group nodes by them, decision 29) and, when the group exists on one surface only, which one.
 */
final readonly class MenuGroupView
{
    /**
     * @param list<MenuRowView> $rows
     * @param null|MenuSurface $only the one surface the whole group is on (menu-only-phone / menu-only-dock)
     * @param bool $headingOnPhoneOnly the docked column heads this group with its place row instead (Puzzles, Community,
     *                                 Your puzzling's Profile row - README §4.3)
     */
    public function __construct(
        public MenuGroup $group,
        public int $orderPhone,
        public int $orderDock,
        public array $rows,
        public null|MenuSurface $only = null,
        public bool $headingOnPhoneOnly = false,
    ) {
    }

    public function id(): string
    {
        return $this->group->value;
    }

    public function hasHeading(): bool
    {
        return $this->group->hasHeading() && $this->only !== MenuSurface::Dock;
    }

    /**
     * The docked column's groups fold behind a disclosure button (README §4.3) - not the places, which head the column
     */
    public function isCollapsible(): bool
    {
        return $this->hasHeading() && !in_array($this->group, [MenuGroup::Home, MenuGroup::Puzzles, MenuGroup::Community], true);
    }
}
