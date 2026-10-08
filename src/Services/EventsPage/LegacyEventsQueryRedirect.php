<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventsPage;

use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * The old events page's query parameters, redirected (301) to the new page's state
 * (docs/features/events-page/README.md, "URL parameters and redirects"):
 *
 * - `?timePeriod=past` without a scope → `events_archive` of the newest year with a past public occurrence;
 * - `?country=cz&timePeriod=past` (or `onlineOnly`) → the same URL without `timePeriod` (the scope view shows its past);
 * - any other `timePeriod` value is dropped;
 * - `?showCalendar=1` → `?view=calendar`, other `showCalendar` values are dropped;
 * - every other parameter is kept.
 *
 * Null = nothing to redirect, the page renders. Foundation stub - workstream D implements it.
 */
readonly final class LegacyEventsQueryRedirect
{
    public function redirectFor(Request $request): null|RedirectResponse // @phpstan-ignore return.unusedType (stub)
    {
        return null;
    }
}
