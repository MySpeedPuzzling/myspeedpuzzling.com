<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What the Country Cup ranks by (docs/features/players-page/README.md). Per active puzzler is the default: total
 * pieces mostly says how big a country is.
 */
enum CountryCupMeasure: string
{
    case PerActivePuzzler = 'per_puzzler';
    case TotalPieces = 'total';
}
