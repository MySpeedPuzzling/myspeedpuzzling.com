<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Why a competitor page shows a round's result (docs/features/competitions-management/competitor-pages.md "Results"):
 * the organiser published the round (official, with the official place), the organiser chose to show confirmed results
 * before publishing (live - never a place), or it is the viewer's own entry (own - their time as the judge confirmed
 * it, never a place).
 */
enum CompetitorResultState: string
{
    case Official = 'official';
    case Live = 'live';
    case Own = 'own';
}
