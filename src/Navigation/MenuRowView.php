<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * One row of the Menu as rendered (MenuBuilder, MENU-01): what _menu_row.html.twig needs besides the entry itself - the
 * resolved link, whether it is the current page's row (aria-current), which surface only it is on, and its count.
 */
final readonly class MenuRowView
{
    /**
     * @param string $id the entry id (`legal` for the one row of the three Legal entries) - anchor menu-row-<id>
     * @param null|string $href resolved for this viewer and page; null for rows that build their own (You card, Inbox,
     *                          Language, Legal)
     * @param null|MenuSurface $only the one surface this row is on, when its group is on both
     * @param list<MenuRowView> $links the Legal row's links (one per Legal entry)
     * @param null|int $count a number rendered with the page (review queues, line-up size, karma, level)
     * @param null|string $countTestId the review queue's data-testid (queue-count-*, each once per page)
     * @param null|string $lazyCount the menu_counts key filling the reserved slot later (organised, review)
     * @param bool $nofollow rel="nofollow": a page of the viewer's own (as the old account menu had them), or a
     *                       per-page variant crawlers should not follow (the progression row's ?return=, decision 35)
     */
    public function __construct(
        public string $id,
        public NavEntry $entry,
        public MenuGroup $group,
        public null|string $href = null,
        public bool $current = false,
        public null|MenuSurface $only = null,
        public array $links = [],
        public null|int $count = null,
        public null|string $countTestId = null,
        public null|string $lazyCount = null,
        public bool $nofollow = false,
    ) {
    }

    public function kind(): RowKind
    {
        return $this->entry->kind;
    }

    /**
     * data-nav="menu/<group>/<id>" (implementation plan §3.3)
     */
    public function navId(): string
    {
        return 'menu/' . $this->group->value . '/' . $this->entry->id;
    }

    public function anchorId(): string
    {
        return 'menu-row-' . $this->id;
    }
}
