<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Why a one-time event cannot become a series with `keepAsEdition: false` (ConvertCompetitionToSeries,
 * docs/features/events-page/high-frequency-series.md "The conversion tool"): the competition row is deleted, so
 * anything of it that would be lost refuses the conversion. Participants only refuse it without `dropParticipants`.
 */
enum SeriesConversionBlocker: string
{
    case Rounds = 'rounds';
    case OfficialResults = 'official_results';
    case Referees = 'referees';
    case PageSections = 'page_sections';
    case MarketplaceMarks = 'marketplace_marks';
    case Participants = 'participants';
}
