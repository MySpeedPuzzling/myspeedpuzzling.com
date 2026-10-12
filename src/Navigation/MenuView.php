<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * The Menu as one viewer sees it on one surface (MenuBuilder, MENU-01): the groups in DOM order with the rows the viewer
 * may use - rows they may not are left out (hidden, never locked; D13).
 */
final readonly class MenuView
{
    public const string SURFACE_SLOT = 'slot';
    public const string SURFACE_PAGE = 'page';

    /**
     * @param string $surface `slot` = the page's one Menu element in the docked-column slot (DOM in docked-column
     *                        order, phone-only and dock-only parts marked, MENU-02b moves groups into phone order);
     *                        `page` = the `menu` page (phone rows only, in phone order)
     * @param list<MenuGroupView> $groups
     */
    public function __construct(
        public string $surface,
        public NavigationViewer $viewer,
        public array $groups,
    ) {
    }

    /**
     * The row ids in DOM order - what the tests compare between surfaces.
     *
     * @return list<string>
     */
    public function rowIds(): array
    {
        $ids = [];

        foreach ($this->groups as $group) {
            foreach ($group->rows as $row) {
                $ids[] = $row->id;
            }
        }

        return $ids;
    }

    /**
     * One of the viewer's review queues holds items: a Your roles row shows a queue count above 0 - the ☰ dot of the
     * top bar (NAV-03, README §4.12 "Menu data"). Only moderators and admins have such rows; the count comes from the
     * memoised admin_queue_counts() statement the rows already read (no statement of its own).
     */
    public function hasQueueBacklog(): bool
    {
        foreach ($this->groups as $group) {
            foreach ($group->rows as $row) {
                if ($row->countTestId !== null && ($row->count ?? 0) > 0) {
                    return true;
                }
            }
        }

        return false;
    }
}
