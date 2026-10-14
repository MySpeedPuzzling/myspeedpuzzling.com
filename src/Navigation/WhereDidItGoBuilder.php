<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

use SpeedPuzzling\Web\Value\SearchText;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Where did it go?" for the current viewer (docs/features/navigation README §8, LAUNCH-02): every item of the old
 * navigation (FormerNavigation), one section per old surface, each row its old name linking its page and its new
 * place - the Menu path on a phone ("☰ › Account › Your data"), the bars' places ("Bottom bar › Home"; on a computer
 * "Left menu › Home", in the 768-1023 px top bar "Top bar › Home"), the top bar's own tools ("Top bar › Inbox").
 * An action's old name (Log out) links its place in the Menu, never the action (LAUNCH review F3).
 *
 * Who gets which row (standing()): moderator and admin rows only those viewers (as the Menu); a members-only row is
 * marked Members (lock, no modal) for whoever is no member; a guest gets every other row, marked "after signing in"
 * where following it needs an account - the same rows for every guest, so guest HTML stays shared-cacheable. Rows
 * behind an audience the viewer is not in otherwise (the XP pages of a player who switched XP off, the guests' Sign in
 * for a player) are left out, as the Menu leaves them out.
 *
 * No statement: the registry, the viewer from the profile row, the router. Holds nothing between requests.
 */
final readonly class WhereDidItGoBuilder
{
    public const string SEPARATOR = ' › ';
    private const string MENU = '☰';

    // A members-only audience: shown to whoever is no member with the Members mark instead of being left out
    private const array MEMBERS = [NavVisibility::Member, NavVisibility::MemberOrReferral, NavVisibility::PrivateMember];

    /**
     * The top bar's own tools (README D2, D16; NavigationRegistry::ON_BARS): right there next to ☰ on a phone and on a
     * computer, so "Now" names them there (LAUNCH review F1) - their name on the phone's bar (and on the 768-1023 px
     * one: the ? there too), and from 1024 px. Their Menu rows stay findable through the filter (data-search).
     */
    private const array TOP_BAR_TOOLS = [
        'inbox' => ['inbox.title', 'inbox.title'],
        'send_feedback' => ['nav.bar.help', 'nav.bar.feedback'],
    ];

    public function __construct(
        private NavigationRegistry $registry,
        private NavigationViewerFactory $viewerFactory,
        private MenuBuilder $menuBuilder,
        private NavigationSignInRule $signInRule,
        private UrlGeneratorInterface $urlGenerator,
        private RequestStack $requestStack,
        private TranslatorInterface $translator,
    ) {
    }

    /**
     * @return list<WhereDidItGoSection>
     */
    public function sections(): array
    {
        $viewer = $this->viewerFactory->current();
        $locale = $this->requestStack->getCurrentRequest()?->getLocale() ?? 'en';
        $rows = [];
        $positions = [];

        foreach (FormerNavigation::items() as $item) {
            $surface = $item->surface->value;
            // Stable per item, whoever looks
            $positions[$surface] = ($positions[$surface] ?? 0) + 1;
            $row = $this->row($item, 'wdig-' . str_replace('_', '-', $surface) . '-' . $positions[$surface], $viewer, $locale);

            if ($row !== null) {
                $rows[$surface][] = $row;
            }
        }

        $sections = [];

        foreach (FormerSurface::cases() as $surface) {
            if (($rows[$surface->value] ?? []) !== []) {
                $sections[] = new WhereDidItGoSection($surface, $rows[$surface->value]);
            }
        }

        return $sections;
    }

    /**
     * How the viewer gets a row whose new home is for $visibility (the item's audience, else the entry's); null = the
     * row is left out.
     */
    public static function standing(NavVisibility $visibility, NavigationViewer $viewer): null|WhereDidItGoStanding
    {
        return match (true) {
            $viewer->sees($visibility) => WhereDidItGoStanding::Open,
            // Moderator, time verifier and admin rows: those viewers only, as in the Menu
            $visibility->isRole() => null,
            // The Members mark for whoever is no member; a member outside a narrower members' audience (a private
            // profile's allow list, for a member with a public profile) has no such row at all (LAUNCH review F4)
            in_array($visibility, self::MEMBERS, true) => $viewer->sees(NavVisibility::Member) ? null : WhereDidItGoStanding::MembersOnly,
            $viewer->isGuest() && !$visibility->includesGuests() => WhereDidItGoStanding::Guest,
            // A player who switched the XP system off, an organiser's row for somebody who organises nothing, the
            // guests' own Sign in and Join free for a signed-in player
            default => null,
        };
    }

    private function row(FormerNavigationItem $item, string $id, NavigationViewer $viewer, string $locale): null|WhereDidItGoRow
    {
        $entry = $this->entry($item->entry);
        $link = $this->entry($item->linkEntry());
        $standing = self::standing($item->audience ?? $entry->visibility, $viewer);

        if ($standing === null) {
            return null;
        }

        $url = $this->menuBuilder->urlFor($link, $viewer);
        $afterSignIn = match ($standing) {
            WhereDidItGoStanding::Open => false,
            WhereDidItGoStanding::MembersOnly => $viewer->isGuest(),
            // Only where a guest really needs an account (LAUNCH review F4): the item says its new home is for
            // signed-in players (the Hub is Home once signed in), the page is the viewer's own (no link without a
            // player), or its route asks a guest to sign in - the page search's rule (NavigationSignInRule); never a
            // page that opens for guests (What next?'s landing, the Help sheet's page)
            WhereDidItGoStanding::Guest => $item->audience !== null
                || $url === null
                || ($link->target->guestRoute === null && $this->signInRule->asksGuestsToSignIn($link)),
        };

        $oldName = $item->label !== null
            ? $this->translator->trans($item->label, [], null, $locale)
            : $this->registry->label($entry, $locale);

        [$now, $nowTablet, $nowDesktop, $nowHref, $menuPaths] = $this->now($item, $entry, $locale);

        // An action (Log out) links its place, never the action itself (LAUNCH review F3)
        $href = $link->kind->isAction()
            ? ($nowHref ?? $this->urlGenerator->generate('menu'))
            : ($url
                ?? ($afterSignIn ? SignInLink::href($this->urlGenerator, $this->requestStack->getMainRequest()) : null)
                ?? $nowHref
                ?? $this->urlGenerator->generate('menu'));

        return new WhereDidItGoRow(
            id: $id,
            oldName: $oldName,
            href: $href,
            now: $now,
            nowTablet: $nowTablet,
            nowDesktop: $nowDesktop,
            nowHref: $nowHref,
            members: $standing === WhereDidItGoStanding::MembersOnly,
            afterSignIn: $afterSignIn,
            search: SearchText::fold(implode(' ', array_unique(array_filter(
                [$oldName, $now, $nowTablet, $nowDesktop, ...$menuPaths],
                static fn (null|string $text): bool => $text !== null && $text !== '',
            )))),
            nameSearch: SearchText::fold($oldName),
            navId: 'page/none/wdig_' . $link->id,
            nowNavId: 'page/none/wdig_now_' . $entry->id,
        );
    }

    /**
     * The new place on a phone, at 768-1023 px when it differs from the computer's, on a computer when it differs from
     * the phone's, the Menu row to link, and Menu paths that only the filter searches.
     *
     * @return array{string, null|string, null|string, null|string, list<string>}
     */
    private function now(FormerNavigationItem $item, NavEntry $entry, string $locale): array
    {
        $label = $this->registry->label($entry, $locale);
        $bars = NavigationRegistry::ON_BARS[$entry->id] ?? [];

        if ($item->path !== []) {
            $path = implode(self::SEPARATOR, array_map(
                fn (string $key): string => $key === self::MENU ? self::MENU : $this->translator->trans($key, [], null, $locale),
                $item->path,
            ));

            // The guest card's Sign in / Join free are at the top of the Menu
            return [$path, null, null, $item->path[0] === self::MENU ? $this->urlGenerator->generate('menu') : null, []];
        }

        // The places: the phone's bottom bar, from 1024 px the computer's left menu (the rail or the docked column).
        // In between there is no left menu (D16): the compact top bar carries Home, Puzzles and Community, the account
        // menu (avatar ▾) the profile (LAUNCH review F1)
        if ($entry->kind === RowKind::Place) {
            $tablet = match (true) {
                in_array(NavigationRegistry::BAR_ACCOUNT, $bars, true) => $this->path($locale, 'where_did_it_go.account_menu', $this->translator->trans('nav.account.view_profile', [], null, $locale)),
                in_array(NavigationRegistry::BAR_DESKTOP, $bars, true) => $this->path($locale, 'where_did_it_go.top_bar', $label),
                default => null,
            };

            return [
                $this->path($locale, 'where_did_it_go.bottom_bar', $label),
                $tablet,
                $this->path($locale, 'where_did_it_go.left_menu', $label),
                null,
                [],
            ];
        }

        // Add: the bottom bar's coral tile, the computer's top bar (+ Add from 768 px)
        if ($entry->id === 'add') {
            return [
                $this->path($locale, 'where_did_it_go.bottom_bar', $label),
                null,
                $this->path($locale, 'where_did_it_go.top_bar', $label),
                null,
                [],
            ];
        }

        // The top bar's tools lead with the top bar - the phone's ? (also at 768-1023 px), the computer's Feedback
        if (isset(self::TOP_BAR_TOOLS[$entry->id])) {
            [$phoneTool, $desktopTool] = self::TOP_BAR_TOOLS[$entry->id];
            $phone = $this->path($locale, 'where_did_it_go.top_bar', $this->translator->trans($phoneTool, [], null, $locale));
            $desktop = $this->path($locale, 'where_did_it_go.top_bar', $this->translator->trans($desktopTool, [], null, $locale));
            $menuPaths = array_values(array_filter([
                $this->registry->menuPathOn(MenuSurface::Phone, $entry->id, $locale, self::SEPARATOR),
                $this->registry->menuPathOn(MenuSurface::Dock, $entry->id, $locale, self::SEPARATOR),
            ], static fn (null|string $path): bool => $path !== null));

            return $desktop === $phone
                ? [$phone, null, null, null, $menuPaths]
                : [$phone, $phone, $desktop, null, $menuPaths];
        }

        $phone = $this->registry->menuPathOn(MenuSurface::Phone, $entry->id, $locale, self::SEPARATOR)
            ?? (in_array(NavigationRegistry::BAR_PHONE, $bars, true) ? $this->path($locale, 'where_did_it_go.top_bar', $label) : null);
        $desktop = $this->registry->menuPathOn(MenuSurface::Dock, $entry->id, $locale, self::SEPARATOR)
            ?? (in_array(NavigationRegistry::BAR_DESKTOP, $bars, true) ? $this->path($locale, 'where_did_it_go.top_bar', $label) : null)
            ?? (in_array(NavigationRegistry::BAR_ACCOUNT, $bars, true) ? $this->path($locale, 'where_did_it_go.account_menu', $label) : null);
        $now = $phone ?? $desktop ?? $label;

        return [$now, null, $desktop !== null && $desktop !== $now ? $desktop : null, $this->menuRowHref($entry), []];
    }

    private function path(string $locale, string $placeKey, string $label): string
    {
        return $this->translator->trans($placeKey, [], null, $locale) . self::SEPARATOR . $label;
    }

    /**
     * The entry's row on the `menu` page (phone rows, anchors menu-row-<id>; the three legal links share one row): its
     * own, or its parent's for a search-only entry; null when the phone Menu has no row for it.
     */
    private function menuRowHref(NavEntry $entry): null|string
    {
        $placement = $entry->phone;

        if ($placement->isSearchOnly() && $placement->parent !== null) {
            $parent = $this->registry->byId($placement->parent);

            return $parent !== null && $parent->phone->isRow() ? $this->anchor($parent) : null;
        }

        return $placement->isRow() ? $this->anchor($entry) : null;
    }

    private function anchor(NavEntry $entry): string
    {
        return $this->urlGenerator->generate('menu') . '#menu-row-' . ($entry->kind === RowKind::Legal ? 'legal' : $entry->id);
    }

    private function entry(string $id): NavEntry
    {
        return $this->registry->byId($id) ?? throw new \LogicException(sprintf('"Where did it go?" names "%s", which is not a registry entry', $id));
    }
}
