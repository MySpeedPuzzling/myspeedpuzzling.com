<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Results\ComparisonResult;
use SpeedPuzzling\Web\Results\ComparisonSubject;
use Symfony\UX\TwigComponent\Attribute\AsTwigComponent;

/**
 * Members' charts on the Charts tab of the compare page (docs/features/player-comparison.md). Placeholder - built out
 * separately.
 */
#[AsTwigComponent]
final class ComparisonCharts
{
    public null|ComparisonResult $result = null;

    /** @var list<ComparisonSubject> */
    public array $subjects = [];
}
