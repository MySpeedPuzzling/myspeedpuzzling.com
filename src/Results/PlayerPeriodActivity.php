<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\AchievementDrop;
use SpeedPuzzling\Web\Value\BadgeTier;
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
        /** XP still missing to the next level at the period's end, null at Level 50 */
        public null|int $xpToNextLevel,
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
        /** The period before, counted the same way - the change under the numbers */
        public int $previousPuzzlesCount = 0,
        public int $previousPiecesCount = 0,
        /** Members: achievements with a tier still waiting for its reveal, now - not only the period's */
        public int $unrevealedAchievements = 0,
        /** The achievement drop released in the period (AchievementDrops), announced to everybody */
        public null|AchievementDrop $achievementDrop = null,
        /** How many of that drop's achievements the player already holds (awarded from their history) */
        public int $achievementDropHeld = 0,
    ) {
    }

    /**
     * Change against the period before, null when there is nothing to compare: the period before was empty or the
     * numbers are equal.
     */
    public function puzzlesChange(): null|int
    {
        return self::change($this->puzzlesCount, $this->previousPuzzlesCount);
    }

    public function piecesChange(): null|int
    {
        return self::change($this->piecesCount, $this->previousPiecesCount);
    }

    /**
     * The achievements of the period by their points, highest first - the e-mail shows the first few.
     *
     * @return list<BadgeResult>
     */
    public function achievementsByPoints(): array
    {
        $achievements = $this->achievementsEarned;

        usort($achievements, static fn (BadgeResult $a, BadgeResult $b): int => ($b->tier?->points() ?? BadgeTier::SINGLE_TIER_POINTS) <=> ($a->tier?->points() ?? BadgeTier::SINGLE_TIER_POINTS));

        return $achievements;
    }

    /**
     * @return null|array{type: BadgeType, progress: BadgeProgress}
     */
    public function nextAchievement(): null|array
    {
        return $this->nextAchievements[0] ?? null;
    }

    private static function change(int $now, int $before): null|int
    {
        if ($before === 0 || $now === $before) {
            return null;
        }

        return $now - $before;
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
