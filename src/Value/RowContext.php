<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where an agenda row or archive line is built for (EventRowFactory): the events page, or one series' own page - there
 * the series is the page, so a row names its edition/session, carries no "Recurring" tag and no star.
 */
enum RowContext
{
    case EventsPage;
    case SeriesPage;
}
