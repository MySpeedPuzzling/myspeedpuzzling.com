<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Component;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetComparisonPlayers;
use SpeedPuzzling\Web\Query\SearchPlayers;
use SpeedPuzzling\Web\Results\ComparisonPlayer;
use SpeedPuzzling\Web\Results\ComparisonView;
use SpeedPuzzling\Web\Services\ComparisonBucket;
use SpeedPuzzling\Web\Services\ComparisonBuilder;
use SpeedPuzzling\Web\Services\ComparisonChartBuilder;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ComparisonFilter;
use SpeedPuzzling\Web\Value\ComparisonMode;
use SpeedPuzzling\Web\Value\ComparisonSubject;
use Symfony\UX\Chartjs\Model\Chart;
use Symfony\UX\LiveComponent\Attribute\AsLiveComponent;
use Symfony\UX\LiveComponent\Attribute\LiveAction;
use Symfony\UX\LiveComponent\Attribute\LiveArg;
use Symfony\UX\LiveComponent\Attribute\LiveProp;
use Symfony\UX\LiveComponent\Attribute\PreReRender;
use Symfony\UX\LiveComponent\DefaultActionTrait;

#[AsLiveComponent]
final class PlayerComparison
{
    use DefaultActionTrait;

    private const int NON_MEMBER_SUBJECT_LIMIT = 2;

    #[LiveProp(writable: true, url: true)]
    public string $mode = 'solo';

    #[LiveProp(writable: true, url: true)]
    public string $view = 'table';

    #[LiveProp(writable: true, url: true)]
    public string $search = '';

    #[LiveProp(writable: true, url: true)]
    public string $manufacturer = '';

    #[LiveProp(writable: true, url: true)]
    public string $pieces = '';

    #[LiveProp(writable: true, url: true)]
    public string $sort = 'name';

    #[LiveProp(writable: true, url: true)]
    public bool $onlyCommon = false;

    #[LiveProp(writable: true, url: true)]
    public string $baseline = '';

    #[LiveProp(writable: true, url: true)]
    public string $chart = 'wins';

    #[LiveProp(writable: true)]
    public string $addQuery = '';

    #[LiveProp(writable: true)]
    public string $coSolverTarget = '';

    #[LiveProp(writable: true)]
    public string $coSolverQuery = '';

    private null|ComparisonView $cachedView = null;

    public function __construct(
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly ComparisonBucket $comparisonBucket,
        private readonly ComparisonBuilder $comparisonBuilder,
        private readonly ComparisonChartBuilder $comparisonChartBuilder,
        private readonly SearchPlayers $searchPlayers,
        private readonly GetComparisonPlayers $getComparisonPlayers,
    ) {
    }

    #[PreReRender]
    public function clearCache(): void
    {
        $this->cachedView = null;
    }

    public function isMember(): bool
    {
        $profile = $this->retrieveLoggedUserProfile->getProfile();

        return $profile !== null && $profile->activeMembership;
    }

    public function modeEnum(): ComparisonMode
    {
        return ComparisonMode::fromString($this->mode);
    }

    public function bucketCount(): int
    {
        return $this->comparisonBucket->count();
    }

    public function canAddMore(): bool
    {
        return $this->isMember() || $this->comparisonBucket->count() < self::NON_MEMBER_SUBJECT_LIMIT;
    }

    public function gatedCount(): int
    {
        if ($this->isMember()) {
            return 0;
        }

        return max(0, $this->comparisonBucket->count() - self::NON_MEMBER_SUBJECT_LIMIT);
    }

    public function getComparisonView(): ComparisonView
    {
        if ($this->cachedView !== null) {
            return $this->cachedView;
        }

        $effectiveSort = ($this->sort === 'difficulty' && $this->isMember() === false) ? 'name' : $this->sort;

        $filter = new ComparisonFilter(
            search: $this->search,
            manufacturerId: $this->manufacturer,
            pieces: $this->pieces !== '' ? (int) $this->pieces : null,
            sort: $effectiveSort,
            onlyCommon: $this->onlyCommon,
            baselineKey: $this->baseline,
        );

        $self = $this->retrieveLoggedUserProfile->getProfile();

        $this->cachedView = $this->comparisonBuilder->build(
            $this->getActiveSubjects(),
            $this->modeEnum(),
            $filter,
            $this->isMember(),
            $self?->playerId,
        );

        return $this->cachedView;
    }

    public function getChartObject(): Chart
    {
        return $this->comparisonChartBuilder->build($this->chart, $this->getComparisonView());
    }

    public function chartHasData(): bool
    {
        return $this->comparisonChartBuilder->hasData($this->chart, $this->getComparisonView());
    }

    /**
     * @return list<ComparisonPlayer>
     */
    public function getSearchResults(): array
    {
        $query = trim($this->addQuery);

        if (mb_strlen($query) < 2) {
            return [];
        }

        return $this->filterSearchResults($query, $this->comparisonBucket->playerIds());
    }

    /**
     * @return list<ComparisonPlayer>
     */
    public function getCoSolverResults(): array
    {
        $query = trim($this->coSolverQuery);

        if ($this->coSolverTarget === '' || mb_strlen($query) < 2) {
            return [];
        }

        $excluded = [$this->coSolverTarget];

        foreach ($this->comparisonBucket->getSubjects() as $subject) {
            if ($subject->playerId === $this->coSolverTarget) {
                $excluded = array_merge($excluded, $subject->coSolverIds);
            }
        }

        return $this->filterSearchResults($query, $excluded);
    }

    #[LiveAction]
    public function addPlayer(#[LiveArg] string $id): void
    {
        if (Uuid::isValid($id) === false || $this->canAddMore() === false) {
            return;
        }

        $player = $this->getComparisonPlayers->byIds([$id])[$id] ?? null;

        if ($player === null) {
            return;
        }

        if ($player->isPrivate && $player->playerId !== $this->retrieveLoggedUserProfile->getProfile()?->playerId) {
            return;
        }

        $this->comparisonBucket->addPlayer($id);
        $this->addQuery = '';
    }

    #[LiveAction]
    public function removePlayer(#[LiveArg] string $id): void
    {
        $this->comparisonBucket->removePlayer($id);

        if ($this->baseline !== '' && str_starts_with($this->baseline, $id)) {
            $this->baseline = '';
        }
    }

    #[LiveAction]
    public function startCoSolver(#[LiveArg] string $id): void
    {
        $this->coSolverTarget = $id;
        $this->coSolverQuery = '';
    }

    #[LiveAction]
    public function cancelCoSolver(): void
    {
        $this->coSolverTarget = '';
        $this->coSolverQuery = '';
    }

    #[LiveAction]
    public function addCoSolver(#[LiveArg] string $subject, #[LiveArg] string $partner): void
    {
        if (Uuid::isValid($partner) === false) {
            return;
        }

        $player = $this->getComparisonPlayers->byIds([$partner])[$partner] ?? null;

        if ($player === null) {
            return;
        }

        if ($player->isPrivate && $player->playerId !== $this->retrieveLoggedUserProfile->getProfile()?->playerId) {
            return;
        }

        $this->comparisonBucket->addCoSolver($subject, $partner);
        $this->coSolverTarget = '';
        $this->coSolverQuery = '';
        $this->baseline = '';
    }

    #[LiveAction]
    public function removeCoSolver(#[LiveArg] string $subject, #[LiveArg] string $partner): void
    {
        $this->comparisonBucket->removeCoSolver($subject, $partner);
        $this->baseline = '';
    }

    #[LiveAction]
    public function clearAll(): void
    {
        $this->comparisonBucket->clear();
        $this->baseline = '';
    }

    /**
     * @return list<ComparisonSubject>
     */
    private function getActiveSubjects(): array
    {
        $subjects = $this->comparisonBucket->getSubjects();

        if ($this->isMember()) {
            return $subjects;
        }

        return array_slice($subjects, 0, self::NON_MEMBER_SUBJECT_LIMIT);
    }

    /**
     * @param list<string> $excludedIds
     * @return list<ComparisonPlayer>
     */
    private function filterSearchResults(string $query, array $excludedIds): array
    {
        $matches = $this->searchPlayers->fulltext($query, 8);
        $players = $this->getComparisonPlayers->byIds(array_map(static fn ($match): string => $match->playerId, $matches));
        $selfPlayerId = $this->retrieveLoggedUserProfile->getProfile()?->playerId;

        $results = [];

        foreach ($matches as $match) {
            $player = $players[$match->playerId] ?? null;

            if ($player === null || in_array($player->playerId, $excludedIds, true)) {
                continue;
            }

            if ($player->isPrivate && $player->playerId !== $selfPlayerId) {
                continue;
            }

            $results[] = $player;
        }

        return $results;
    }
}
