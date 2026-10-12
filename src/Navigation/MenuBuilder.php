<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Navigation;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Twig\AddEntryTwigExtension;
use SpeedPuzzling\Web\Twig\AdminQueueCountsTwigExtension;
use SpeedPuzzling\Web\Twig\HelpTwigExtension;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Contracts\Service\ResetInterface;

/**
 * Builds the Menu (docs/features/navigation README §4.12 / D21, MENU-01) from the registry for the current viewer:
 * rows the viewer may not use are left out (never locked, D13), links resolved for this viewer and page, counts from
 * what the page already loads - the profile row, the memoised admin_queue_counts() statement (moderators and admins)
 * - so the Menu costs no statement of its own. The organiser's and Review your results' numbers come later from
 * menu_counts (reserved slots).
 *
 * Two surfaces: the slot (the page's one `#site-menu`, DOM in docked-column order, every row of either surface, the
 * phone-only and dock-only parts marked - MENU-02b moves the group nodes into phone order, decision 29) and the `menu`
 * page (phone rows only, phone order). Memoised per request and surface; reset between requests (worker mode).
 */
final class MenuBuilder implements ResetInterface
{
    // Decision 35: the signed-in viewer's row carries the page it was opened on (rel="nofollow"), guests get the bare link
    private const string PROGRESSION_ROW = 'progression';

    // A group missing on a surface still needs a number to be sorted by there (it is hidden on that surface): the
    // places group comes first on phones, the Inbox follows the You card in the column's DOM, Log out ends it
    private const array FALLBACK_ORDER = [
        'phone' => ['home' => 0],
        'dock' => ['inbox' => 36, 'logout' => 1000],
    ];

    /**
     * @var array<string, MenuView>
     */
    private array $memo = [];

    public function __construct(
        readonly private NavigationRegistry $registry,
        readonly private NavigationViewerFactory $viewerFactory,
        readonly private UrlGeneratorInterface $urlGenerator,
        readonly private RequestStack $requestStack,
        readonly private ClockInterface $clock,
        readonly private AdminQueueCountsTwigExtension $adminQueueCounts,
        readonly private AddEntryTwigExtension $addEntry,
        readonly private HelpTwigExtension $help,
    ) {
    }

    /**
     * @param string $surface MenuView::SURFACE_SLOT or MenuView::SURFACE_PAGE
     * @param null|string $currentRowId the row of the current page (nav_location().menuRowId) - aria-current
     */
    public function build(string $surface, null|string $currentRowId): MenuView
    {
        return $this->memo[$surface . '|' . $currentRowId] ??= $this->assemble($surface, $currentRowId);
    }

    public function reset(): void
    {
        $this->memo = [];
    }

    /**
     * An entry's link for the viewer, as its Menu row has it (guests' route, the viewer's own parameters, the fragment) -
     * null when it cannot be built for them. The page search's nav_go resolves its entries with it (MENU-03, R14).
     */
    public function urlFor(NavEntry $entry, NavigationViewer $viewer): null|string
    {
        $href = $this->href($entry, $viewer);

        return $href === false ? null : $href;
    }

    private function assemble(string $surface, null|string $currentRowId): MenuView
    {
        $viewer = $this->viewerFactory->current();
        $page = $surface === MenuView::SURFACE_PAGE;
        $request = $this->requestStack->getMainRequest();

        /** @var array<string, array{MenuGroup, list<array{int, NavEntry, null|MenuSurface}>}> $collected */
        $collected = [];

        foreach ($this->registry->all() as $entry) {
            $onPhone = $entry->phone->isRow();
            $onDock = $entry->dock->isRow();

            if ((!$onPhone && !$onDock) || ($page && !$onPhone) || !$viewer->sees($entry->visibility)) {
                continue;
            }

            // The slot's DOM is in docked-column order: a row on both surfaces sits where the column has it (both
            // surfaces share group and position - MenuRowsTest), a phone-only row where the panel has it
            $placement = !$page && $onDock ? $entry->dock : $entry->phone;
            $group = $placement->group;
            assert($group !== null && $placement->position !== null);

            $only = match (true) {
                $page, $onPhone && $onDock => null,
                $onPhone => MenuSurface::Phone,
                default => MenuSurface::Dock,
            };

            $collected[$group->value][0] = $group;
            $collected[$group->value][1][] = [$placement->position, $entry, $only];
        }

        $groups = [];

        foreach ($collected as [$group, $items]) {
            $rows = $this->rows($group, $items, $viewer, $currentRowId, $request);

            if ($rows === []) {
                continue;
            }

            $groups[] = $this->group($group, $rows);
        }

        usort($groups, static fn (MenuGroupView $a, MenuGroupView $b): int => $page
            ? $a->orderPhone <=> $b->orderPhone
            : $a->orderDock <=> $b->orderDock);

        return new MenuView($surface, $viewer, $groups);
    }

    /**
     * @param list<MenuRowView> $rows
     */
    private function group(MenuGroup $group, array $rows): MenuGroupView
    {
        $surfaces = array_values(array_unique(array_map(static fn (MenuRowView $row): string => $row->only->value ?? 'both', $rows)));
        $only = count($surfaces) === 1 && $surfaces[0] !== 'both' ? MenuSurface::from($surfaces[0]) : null;

        if ($only !== null) {
            // The whole group is on one surface: the section says so, its rows need not
            $rows = array_map(static fn (MenuRowView $row): MenuRowView => new MenuRowView(
                id: $row->id,
                entry: $row->entry,
                group: $row->group,
                href: $row->href,
                current: $row->current,
                only: null,
                links: $row->links,
                count: $row->count,
                countTestId: $row->countTestId,
                lazyCount: $row->lazyCount,
                nofollow: $row->nofollow,
            ), $rows);
        }

        $headedByPlace = false;

        foreach ($rows as $row) {
            if ($row->kind() === RowKind::Place && $row->only === MenuSurface::Dock) {
                $headedByPlace = true;
            }
        }

        return new MenuGroupView(
            group: $group,
            orderPhone: $group->order(MenuSurface::Phone) ?? self::FALLBACK_ORDER['phone'][$group->value] ?? 0,
            orderDock: $group->order(MenuSurface::Dock) ?? self::FALLBACK_ORDER['dock'][$group->value] ?? 1000,
            rows: $rows,
            only: $only,
            headingOnPhoneOnly: $headedByPlace,
        );
    }

    /**
     * @param list<array{int, NavEntry, null|MenuSurface}> $items
     * @return list<MenuRowView>
     */
    private function rows(MenuGroup $group, array $items, NavigationViewer $viewer, null|string $currentRowId, null|Request $request): array
    {
        // Stable: entries sharing a position keep registry order (the Legal links)
        usort($items, static fn (array $a, array $b): int => $a[0] <=> $b[0]);

        $rows = [];
        $legal = [];

        foreach ($items as [, $entry, $only]) {
            $row = $this->row($entry, $group, $only, $viewer, $currentRowId, $request);

            if ($row === null) {
                continue;
            }

            if ($entry->kind === RowKind::Legal) {
                $legal[] = $row;

                if (count($legal) > 1) {
                    continue;
                }

                // The first Legal entry holds the row's place; its links are filled in below
                $rows[] = 'legal';

                continue;
            }

            $rows[] = $row;
        }

        return array_map(static function (MenuRowView|string $row) use ($legal, $group): MenuRowView {
            if ($row instanceof MenuRowView) {
                return $row;
            }

            $first = $legal[0];

            return new MenuRowView(
                id: 'legal',
                entry: $first->entry,
                group: $group,
                current: array_any($legal, static fn (MenuRowView $link): bool => $link->current),
                only: $first->only,
                links: $legal,
            );
        }, $rows);
    }

    private function row(NavEntry $entry, MenuGroup $group, null|MenuSurface $only, NavigationViewer $viewer, null|string $currentRowId, null|Request $request): null|MenuRowView
    {
        $href = match ($entry->kind) {
            RowKind::Language => null,
            RowKind::GuestCard => $this->signInHref($request),
            RowKind::HelpSheet => $this->help->helpPath(),
            default => $this->href($entry, $viewer),
        };

        if ($href === false) {
            return null;
        }

        // A page of the viewer's own (its URL names them): nofollow, as the old account menu had it
        $nofollow = $entry->target->needsViewer();
        $profile = $viewer->profile;

        if ($entry->target->route === 'puzzle_add') {
            // One rule for every Add entry point: a puzzle page's Add carries its puzzle (ADD-03)
            $href = $this->addEntry->addEntryUrl();
        } elseif ($entry->count === CountSource::Stopwatch && $profile?->runningStopwatch !== null) {
            // While it runs the row leads back to it, like the top bar's pill
            $href = $this->urlGenerator->generate('stopwatch', ['stopwatchId' => $profile->runningStopwatch->stopwatchId]);
        } elseif ($entry->id === self::PROGRESSION_ROW && !$viewer->isGuest()) {
            $nofollow = true;
            $return = $request !== null ? ReturnUrl::tryFrom($request->getRequestUri()) : null;
            $route = $request?->attributes->get('_canonical_route') ?? $request?->attributes->get('_route');

            if ($return !== null && $route !== 'progression_hub') {
                $href = $this->href($entry, $viewer, ['return' => $return->path]);
            }
        }

        [$count, $testId, $lazy] = $this->count($entry, $viewer);

        return new MenuRowView(
            id: $entry->id,
            entry: $entry,
            group: $group,
            href: is_string($href) ? $href : null,
            current: $currentRowId !== null && $currentRowId === $entry->id,
            only: $only,
            count: $count,
            countTestId: $testId,
            lazyCount: $lazy,
            nofollow: $nofollow,
        );
    }

    /**
     * @return array{null|int, null|string, null|string} the number, its queue-count test id, the menu_counts key
     */
    private function count(NavEntry $entry, NavigationViewer $viewer): array
    {
        $profile = $viewer->profile;

        if ($entry->count === null || $profile === null) {
            return [null, null, null];
        }

        if (in_array($entry->count, [CountSource::LazyOrganised, CountSource::LazyReview], true)) {
            return [null, null, $entry->count === CountSource::LazyOrganised ? 'organised' : 'review'];
        }

        $queue = match ($entry->count) {
            CountSource::QueueChangeRequests => 'change-requests',
            CountSource::QueueMergeRequests => 'merge-requests',
            CountSource::QueuePuzzleApprovals => 'puzzle-approvals',
            CountSource::QueueTimeVerification => 'time-verification',
            CountSource::QueueCompetitionApprovals => 'competition-approvals',
            CountSource::QueueOauth2Requests => 'oauth2-requests',
            CountSource::QueueDuplicateSignals => 'duplicate-puzzle-signals',
            default => null,
        };

        if ($queue !== null) {
            if (!$viewer->moderator) {
                return [null, null, null];
            }

            // The memoised per-page statement: the ☰ dot (NAV-03) and these rows share it
            $counts = $this->adminQueueCounts->forViewer($viewer->admin);
            $value = match ($entry->count) {
                CountSource::QueueChangeRequests => $counts->changeRequests,
                CountSource::QueueMergeRequests => $counts->mergeRequests,
                CountSource::QueuePuzzleApprovals => $counts->puzzleApprovals,
                CountSource::QueueTimeVerification => $counts->timeVerification,
                CountSource::QueueCompetitionApprovals => $counts->competitionApprovals,
                CountSource::QueueOauth2Requests => $counts->oauth2Requests,
                default => $counts->duplicatePuzzleSignals,
            };

            return $value === null ? [null, null, null] : [$value, 'queue-count-' . $queue, null];
        }

        $value = match ($entry->count) {
            CountSource::LineUp => $profile->comparisonLineUp->count(),
            CountSource::Karma => $profile->karma,
            CountSource::Level => $profile->level,
            default => null,
        };

        return [$value !== null && $value > 0 ? $value : null, null, null];
    }

    /**
     * The entry's link for this viewer: guests get the guests' route; parameters filled per viewer. False = the link
     * cannot be built for this viewer (a parameter they do not have), so the row is left out.
     *
     * @param array<string, string> $query
     */
    private function href(NavEntry $entry, NavigationViewer $viewer, array $query = []): false|string
    {
        $target = $entry->target;
        $route = $viewer->isGuest() && $target->guestRoute !== null ? $target->guestRoute : $target->route;

        if ($route === null) {
            return false;
        }

        $parameters = $target->params;

        if ($route === $target->route) {
            foreach ($target->dynamicParams as $name => $source) {
                $value = match ($source) {
                    RouteParam::ViewerPlayerId => $viewer->playerId(),
                    RouteParam::ViewerCountryCode => $viewer->profile?->countryCode?->name,
                    RouteParam::CurrentYear => (int) $this->clock->now()->format('Y'),
                };

                if ($value === null) {
                    return false;
                }

                $parameters[$name] = $value;
            }
        }

        $url = $this->urlGenerator->generate($route, [...$parameters, ...$query]);

        return $target->fragment !== null ? $url . '#' . $target->fragment : $url;
    }

    /**
     * Sign in, coming back to the page it was opened on (README §4.12; the page is the same for every guest at that
     * URL, so guest HTML stays shared-cacheable) - one rule with the guests' Profile tab (NAV-01)
     */
    private function signInHref(null|Request $request): string
    {
        return SignInLink::href($this->urlGenerator, $request);
    }
}
