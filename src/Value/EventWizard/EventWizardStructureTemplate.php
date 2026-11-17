<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\EventWizard;

/**
 * The several-rounds path's follow-up question, "Do competitors qualify for later rounds?"
 * (W-creation-wizard-research.md §3.1, §3.5) - the template the Structure step starts from:
 *
 * - Qualification (yes): stages - groups, semifinals, final - the best of each round going on;
 * - EveryRoundCounts (no): solo, pair and team rounds, the times added up per category;
 * - Empty (not sure yet): no round - the organiser adds them one by one, or picks a template later.
 *
 * Asked on the entry screen, carried to the Structure step as `?structure=` - it is only the step's preselected
 * template, nothing stored.
 */
enum EventWizardStructureTemplate: string
{
    case Qualification = 'qualification';
    case EveryRoundCounts = 'every_round_counts';
    case Empty = 'empty';
}
