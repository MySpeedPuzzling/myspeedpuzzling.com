<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * How AdvanceQualified spreads the qualified entries over the target rounds:
 * - single: one target round takes everybody
 * - balanced: serpentine by advancement seed over the targets in the organiser's order (1→T1, 2→T2, 3→T2, 4→T1, ...)
 * - by_source: an explicit map, every source round to one target round
 */
enum AdvanceDistribution: string
{
    case Single = 'single';
    case Balanced = 'balanced';
    case BySource = 'by_source';
}
