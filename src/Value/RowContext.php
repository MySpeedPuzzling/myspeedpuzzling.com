<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where an agenda row or archive line is built for (EventRowFactory): the events page, one series' own page - there
 * the series is the page, so a row names its edition/session, carries no "Recurring" tag and no star - or an
 * organization's page (docs/features/organizations/README.md): named like the events page, "Recurring" kept, no star.
 */
enum RowContext
{
    case EventsPage;
    case SeriesPage;
    case OrganizationPage;
}
