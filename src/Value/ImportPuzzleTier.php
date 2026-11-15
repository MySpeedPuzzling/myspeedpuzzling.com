<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What the pairing rules say about one distinct puzzle of an import (docs/features/data-import/pairing.md "Tiers").
 * It is the rules' word and stays when the player decides otherwise - "where the rules end" is counted from it.
 */
enum ImportPuzzleTier: string
{
    /** One catalogue puzzle by a rule strong enough to be ticked for the player */
    case Found = 'found';
    /** One catalogue puzzle by a weaker rule - never ticked for the player */
    case Probably = 'probably';
    /** Several candidates, similar names, or a candidate with another piece count */
    case Choose = 'choose';
    /** Nothing, after the similar-name search too */
    case None = 'none';
}
