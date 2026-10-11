<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\BadgeType;
use SpeedPuzzling\Web\Value\DigestPeriod;

/**
 * Everything one player did in one digest period - what the activity digest e-mail and the "Your week" page show
 * (PlayerPeriodActivityReader). Every number is counted the same way in both, so a line of the e-mail always finds
 * itself on the page.
 */
readonly final class PlayerPeriodActivity
{
    /**
     * @param list<PeriodResult> $results newest solve first, the ones awaiting verification included (listed, never counted)
     * @param list<BadgeResult> $achievementsEarned members get them shown, everybody else a count
     * @param list<array{playerId: string, name: string, solves: int}> $favoritesActivity
     * @param null|array{puzzleId: string, name: string, solvers: int} $mostSolved
     * @param list<array{type: BadgeType, progress: BadgeProgress}> $nextAchievements members only
     */
    public function __construct(
        public DigestPeriod $period,
        public array $results,
        public int $puzzlesCount,
        public int $piecesCount,
        public int $minutesSpent,
        /** False when the player hid the experience system - no XP or level anywhere */
        public bool $xpVisible,
        public int $xpGained,
        public int $levelBefore,
        public int $levelAfter,
        /** 0.0-1.0 toward the next level at the period's end, null at Level 50 */
        public null|float $levelProgress,
        public bool $isMember,
        public array $achievementsEarned,
        public int $achievementPointsGained,
        public null|PeriodRatingChange $rating,
        public int $streakDays,
        public array $favoritesActivity,
        public null|array $mostSolved,
        public array $nextAchievements,
        /**
         * Karma gained in the period - null until workstream F derives karma (`player.karma` + its history read
         * model); PlayerPeriodActivityReader::karmaGained() is the one place to wire it.
         */
        public null|int $karmaGained = null,
    ) {
    }

    public function hadActivity(): bool
    {
        return $this->puzzlesCount > 0;
    }

    public function levelledUp(): bool
    {
        return $this->xpVisible && $this->levelAfter > $this->levelBefore;
    }

    /**
     * @return list<PeriodResult>
     */
    public function personalBests(): array
    {
        return array_values(array_filter($this->results, static fn (PeriodResult $result): bool => $result->personalBest));
    }

    /**
     * @return list<PeriodResult>
     */
    public function countedResults(): array
    {
        return array_values(array_filter($this->results, static fn (PeriodResult $result): bool => $result->awaitingVerification === false));
    }

    /**
     * The results worth a line in the e-mail: personal bests and first tries first, then the biggest puzzles.
     *
     * @return list<PeriodResult>
     */
    public function highlightedResults(int $limit): array
    {
        $results = $this->countedResults();

        usort($results, static fn (PeriodResult $a, PeriodResult $b): int => [$b->personalBest, $b->firstAttempt, $b->piecesCount, $b->solvedAt] <=> [$a->personalBest, $a->firstAttempt, $a->piecesCount, $a->solvedAt]);

        return array_slice($results, 0, $limit);
    }
}
