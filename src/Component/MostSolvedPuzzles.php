<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use SpeedPuzzling\Web\Query\GetMostSolvedPuzzles as GetMostSolvedPuzzlesQuery;
use SpeedPuzzling\Web\Query\GetRanking;
use SpeedPuzzling\Web\Results\MostSolvedPuzzle;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class MostSolvedPuzzles
{
    use DefaultActionTrait;

    #[LiveProp(writable: true)]
    public string $timespan = 'this_month';

    #[LiveProp]
    public int $limit = 20;

    #[LiveProp]
    public int $showLimit = 4;

    public function __construct(
        readonly private GetMostSolvedPuzzlesQuery $getMostSolvedPuzzles,
        readonly private GetRanking $getRanking,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    /**
     * @return array<MostSolvedPuzzle>
     */
    public function getPuzzles(): array
    {
        return match ($this->timespan) {
            'this_month' => $this->getMostSolvedPuzzles->topInMonth(
                $this->limit,
                $this->getCurrentMonth(),
                $this->getCurrentYear(),
            ),
            'last_month' => $this->getMostSolvedPuzzles->topInMonth(
                $this->limit,
                $this->getLastMonth(),
                $this->getLastYear(),
            ),
            'all_time' => $this->getMostSolvedPuzzles->top($this->limit),
            default => [],
        };
    }

    /**
     * @return array<string, mixed>
     */
    public function getRanking(): array
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            return [];
        }

        return $this->getRanking->allForPlayer($profile->playerId);
    }

    #[LiveAction]
    public function changeTimespan(#[LiveArg] string $timespan): void
    {
        if (in_array($timespan, ['this_month', 'last_month', 'all_time'], true)) {
            $this->timespan = $timespan;
        }
    }

    private function getCurrentMonth(): int
    {
        return (int) date('m');
    }

    private function getCurrentYear(): int
    {
        return (int) date('Y');
    }

    private function getLastMonth(): int
    {
        $currentMonth = $this->getCurrentMonth();

        if ($currentMonth === 1) {
            return 12;
        }

        return $currentMonth - 1;
    }

    private function getLastYear(): int
    {
        $currentMonth = $this->getCurrentMonth();

        if ($currentMonth === 1) {
            return $this->getCurrentYear() - 1;
        }

        return $this->getCurrentYear();
    }
}
