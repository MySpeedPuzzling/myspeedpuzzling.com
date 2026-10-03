<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

/**
 * Members' "Someone at your speed" pick in the add sheet of the compare page (docs/features/player-comparison.md).
 * Emits `comparisonAddSubject` (ref) up to the page component. Placeholder - built out separately.
 */
#[AsLiveComponent]
final class ComparisonSimilarSpeed
{
    use DefaultActionTrait;

    /** @var list<string> */
    #[LiveProp]
    public array $excludedPlayerIds = [];
}
