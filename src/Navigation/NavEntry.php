<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

/**
 * One destination linked by a navigation surface (NavigationRegistry). Every surface - bars, rail, the Menu, the
 * account menu, the Help sheet, the footer, the community landing, the chips - reads its label and icon from here, so a
 * destination has one name and one icon everywhere (README §4.12).
 */
final readonly class NavEntry
{
    /**
     * @param string $id stable: data-nav item, menu_row events, nav_go, menu-row-<id> anchors
     * @param string $label translation key (messages domain); on englishOnly role rows a nav_admin.* key holding the
     *                      English label in every locale (MENU-01)
     * @param string $icon Bootstrap Icons name (bi-*)
     * @param null|string $description translation key of the one-line description (umbrella/ambiguous rows only)
     * @param null|string $synonyms translation key of the search synonyms, `;`-separated (MENU-03: nav.syn.<id> on every
     *                            entry the page search lists, set by NavigationRegistry)
     * @param list<string> $legacyLabels today's chrome keys (menu.*, footer.*, …): extra synonyms and "old name"
     * @param list<string> $activeOn other routes on which this row is the current one (aria-current)
     * @param list<string> $secondary the other entry points besides its home (≤ 2; the Menu, the footer and search are
     *                                indexes and do not count)
     * @param string $since the day this placement was decided (Y-m-d)
     * @param bool $englishOnly moderator/admin rows: English in every locale (their pages are English-only)
     */
    public function __construct(
        public string $id,
        public RouteTarget $target,
        public string $label,
        public string $icon,
        public NavPlace $place,
        public MenuPlacement $phone,
        public MenuPlacement $dock,
        public NavVisibility $visibility,
        public RulebookQuestion $rule,
        public string $reason,
        public string $since = '2026-10-12',
        public RowKind $kind = RowKind::Link,
        public null|string $section = null,
        public null|string $description = null,
        public null|string $synonyms = null,
        public array $legacyLabels = [],
        public null|FooterPlacement $footer = null,
        public null|int $helpSheet = null,
        public null|CountSource $count = null,
        public array $activeOn = [],
        public array $secondary = [],
        public bool $englishOnly = false,
    ) {
    }

    /**
     * The same entry with its search synonyms key (NavigationRegistry gives every entry the page search lists its
     * nav.syn.<id> key - MENU-03).
     */
    public function withSynonyms(string $synonyms): self
    {
        return new self(
            id: $this->id,
            target: $this->target,
            label: $this->label,
            icon: $this->icon,
            place: $this->place,
            phone: $this->phone,
            dock: $this->dock,
            visibility: $this->visibility,
            rule: $this->rule,
            reason: $this->reason,
            since: $this->since,
            kind: $this->kind,
            section: $this->section,
            description: $this->description,
            synonyms: $synonyms,
            legacyLabels: $this->legacyLabels,
            footer: $this->footer,
            helpSheet: $this->helpSheet,
            count: $this->count,
            activeOn: $this->activeOn,
            secondary: $this->secondary,
            englishOnly: $this->englishOnly,
        );
    }

    public function placement(MenuSurface $surface): MenuPlacement
    {
        return $surface === MenuSurface::Phone ? $this->phone : $this->dock;
    }

    /**
     * A row on the phone panel or in the docked column.
     */
    public function isMenuRow(): bool
    {
        return $this->phone->isRow() || $this->dock->isRow();
    }

    /**
     * Found only by the Menu search, on both surfaces.
     */
    public function isSearchOnly(): bool
    {
        return $this->phone->isSearchOnly() && $this->dock->isSearchOnly();
    }

    /**
     * Listed by the page search for everybody it is meant for (nav-search.json, MENU-03): every Menu row and search-only
     * entry but the role rows (moderator, time verifier, admin - rendered into the page for those viewers only,
     * README §4.12 "Search") and the You card (the Profile row is the same destination).
     */
    public function isInPageSearch(): bool
    {
        return ($this->isMenuRow() || $this->isSearchOnly())
            && !$this->visibility->isRole()
            && $this->kind !== RowKind::YouCard;
    }

    /**
     * A role row (moderator, time verifier, admin): found by the page search only on the pages of those viewers.
     */
    public function isInPrivatePageSearch(): bool
    {
        return ($this->isMenuRow() || $this->isSearchOnly()) && $this->visibility->isRole();
    }

    /**
     * Not in the Menu on either surface (its reason says why).
     */
    public function isNotInMenu(): bool
    {
        return $this->phone->isNotInMenu() && $this->dock->isNotInMenu();
    }

    /**
     * Does the route open this entry's destination (its target or the guests' target)?
     */
    public function targets(string $route): bool
    {
        return in_array($route, $this->target->routes(), true);
    }
}
