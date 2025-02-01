<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class PuzzleTimes
{
    use DefaultActionTrait;

    public string $puzzleId = '';

    #[LiveProp]
    public string $category = 'solo';

    #[LiveProp(writable: true)]
    public bool $onlyFirstTries = false;

    #[LiveProp(writable: true)]
    public null|string $country = null;

    private null|int $myRank = null;
    private null|int $fastestTime = null;
    private null|int $averageTime = null;
    private null|int $myTime = null;

    #[LiveAction]
    public function changeResultsCategory(#[LiveArg] string $category): void
    {
        if (in_array($category, ['solo', 'duo', 'group'], true)) {
            $this->category = $category;
        }
    }
}
