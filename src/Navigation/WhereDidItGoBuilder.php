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
 * place - the Menu path on a phone ("☰ › Account › Your data"), the bars' places ("Bottom bar › Home", on a computer
 * "Left menu › Home"), the top bar's tools.
 *
 * Who gets which row: moderator and admin rows only those viewers (as the Menu); a row the viewer has as a member
 * only is marked Members (lock, no modal); a guest gets every other row, marked "after signing in" where the new place
 * needs an account - the same rows for every guest, so guest HTML stays shared-cacheable. Rows behind an audience the
 * viewer is not in otherwise (the XP pages of a player who switched XP off) are left out, as the Menu leaves them out.
 *
 * No statement: the registry, the viewer from the profile row, the router. Holds nothing between requests.
 */
final readonly class WhereDidItGoBuilder
{
    public const string SEPARATOR = ' › ';
    private const string MENU = '☰';

    // A members-only audience: shown to everybody else with the Members mark instead of being left out
    private const array MEMBERS = [NavVisibility::Member, NavVisibility::MemberOrReferral, NavVisibility::PrivateMember];

    public function __construct(
        private NavigationRegistry $registry,
        private NavigationViewerFactory $viewerFactory,
        private MenuBuilder $menuBuilder,
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

    private function row(FormerNavigationItem $item, string $id, NavigationViewer $viewer, string $locale): null|WhereDidItGoRow
    {
        $entry = $this->entry($item->entry);
        $visibility = $item->audience ?? $entry->visibility;
        $members = false;
        $afterSignIn = false;

        if ($visibility->isRole()) {
            if (!$viewer->sees($visibility)) {
                return null;
            }
        } elseif (!$viewer->sees($visibility)) {
            if (in_array($visibility, self::MEMBERS, true)) {
                $members = true;
                $afterSignIn = $viewer->isGuest();
            } elseif ($viewer->isGuest() && !$visibility->includesGuests()) {
                $afterSignIn = true;
            } elseif ($visibility !== NavVisibility::GuestOnly) {
                // A player who switched the XP system off, an organiser's row for somebody who organises nothing
                return null;
            }
        }

        $oldName = $item->label !== null
            ? $this->translator->trans($item->label, [], null, $locale)
            : $this->registry->label($entry, $locale);

        [$now, $nowDesktop, $nowHref] = $this->now($item, $entry, $locale);

        $href = $this->menuBuilder->urlFor($this->entry($item->linkEntry()), $viewer)
            ?? ($afterSignIn ? SignInLink::href($this->urlGenerator, $this->requestStack->getMainRequest()) : null)
            ?? $nowHref
            ?? $this->urlGenerator->generate('menu');

        return new WhereDidItGoRow(
            id: $id,
            oldName: $oldName,
            href: $href,
            now: $now,
            nowDesktop: $nowDesktop,
            nowHref: $nowHref,
            members: $members,
            afterSignIn: $afterSignIn,
            search: SearchText::fold(implode(' ', [$oldName, $now, $nowDesktop ?? ''])),
        );
    }

    /**
     * The new place on a phone, on a computer when it differs, and the Menu row to link.
     *
     * @return array{string, null|string, null|string}
     */
    private function now(FormerNavigationItem $item, NavEntry $entry, string $locale): array
    {
        $label = $this->registry->label($entry, $locale);

        if ($item->path !== []) {
            $path = implode(self::SEPARATOR, array_map(
                fn (string $key): string => $key === self::MENU ? self::MENU : $this->translator->trans($key, [], null, $locale),
                $item->path,
            ));

            // The guest card's Sign in / Join free are at the top of the Menu
            return [$path, null, $item->path[0] === self::MENU ? $this->urlGenerator->generate('menu') : null];
        }

        // The places: the phone's bottom bar, the computer's left menu (the rail or the docked column)
        if ($entry->kind === RowKind::Place) {
            return [
                $this->path($locale, 'where_did_it_go.bottom_bar', $label),
                $this->path($locale, 'where_did_it_go.left_menu', $label),
                null,
            ];
        }

        // Add: the bottom bar's coral tile, the computer's top bar
        if ($entry->id === 'add') {
            return [
                $this->path($locale, 'where_did_it_go.bottom_bar', $label),
                $this->path($locale, 'where_did_it_go.top_bar', $label),
                null,
            ];
        }

        $bars = NavigationRegistry::ON_BARS[$entry->id] ?? [];
        $phone = $this->registry->menuPathOn(MenuSurface::Phone, $entry->id, $locale, self::SEPARATOR)
            ?? (in_array(NavigationRegistry::BAR_PHONE, $bars, true) ? $this->path($locale, 'where_did_it_go.top_bar', $label) : null);
        $desktop = $this->registry->menuPathOn(MenuSurface::Dock, $entry->id, $locale, self::SEPARATOR)
            ?? (in_array(NavigationRegistry::BAR_DESKTOP, $bars, true) ? $this->path($locale, 'where_did_it_go.top_bar', $label) : null)
            ?? (in_array(NavigationRegistry::BAR_ACCOUNT, $bars, true) ? $this->path($locale, 'where_did_it_go.account_menu', $label) : null);
        $now = $phone ?? $desktop ?? $label;

        return [$now, $desktop !== null && $desktop !== $now ? $desktop : null, $this->menuRowHref($entry)];
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
