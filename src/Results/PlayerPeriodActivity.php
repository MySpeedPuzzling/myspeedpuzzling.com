<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\AchievementDrop;
use SpeedPuzzling\Web\Value\BadgeTier;
use SpeedPuzzling\Web\Value\BadgeType;
use DateTimeImmutable;
use SpeedPuzzling\Web\Value\DigestPeriod;
use SpeedPuzzling\Web\Value\DigestPeriodLength;

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

    /**
     * The period's numbers by how they were solved - solo, in pairs, in teams - in that order, only the kinds the
     * period holds. Empty when everything was solo: the totals already say it all. A pair/team puzzle counts with
     * all its pieces for every member, so without this line a team puzzle reads as if it were done alone
     * (feedback 2026-11-02).
     *
     * @return list<array{type: 'solo'|'duo'|'team', puzzles: int, pieces: int}>
     */
    public function splitByPuzzlingType(): array
    {
        $split = [];

        foreach ($this->countedResults() as $result) {
            $type = match ($result->puzzlingType) {
                'duo' => 'duo',
                'team' => 'team',
                default => 'solo',
            };

            $split[$type] ??= ['type' => $type, 'puzzles' => 0, 'pieces' => 0];
            $split[$type]['puzzles']++;
            $split[$type]['pieces'] += $result->piecesCount;
        }

        if (array_keys($split) === ['solo'] || $split === []) {
            return [];
        }

        return array_values(array_filter([$split['solo'] ?? null, $split['duo'] ?? null, $split['team'] ?? null]));
    }

    /**
     * Pieces and puzzles per day of a week or two-week period (Europe/Prague days, like the period) - the e-mail's
     * small bar chart. Every day of the period, empty ones included; a month has too many days for it: empty list.
     *
     * @return list<array{day: DateTimeImmutable, puzzles: int, pieces: int, share: float}> share = pieces ÷ the busiest day's pieces
     */
    public function days(): array
    {
        if ($this->period->length === DigestPeriodLength::Month) {
            return [];
        }

        $days = [];

        for ($day = $this->period->start; $day < $this->period->end; $day = $day->modify('+1 day')) {
            $days[$day->format('Y-m-d')] = ['day' => $day, 'puzzles' => 0, 'pieces' => 0, 'share' => 0.0];
        }

        foreach ($this->countedResults() as $result) {
            $key = $result->solvedAt->setTimezone(DigestPeriod::zone())->format('Y-m-d');

            if (isset($days[$key])) {
                $days[$key]['puzzles']++;
                $days[$key]['pieces'] += $result->piecesCount;
            }
        }

        $busiest = max(array_map(static fn (array $day): int => $day['pieces'], $days) ?: [0]);

        if ($busiest === 0) {
            return [];
        }

        return array_values(array_map(static function (array $day) use ($busiest): array {
            $day['share'] = (float) ($day['pieces'] / $busiest);

            return $day;
        }, $days));
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
     * The results worth a line in the e-mail: one per puzzle (and solo / pair / team) - the period's fastest attempt,
     * with how many attempts the period had - then personal bests and first tries first, then the biggest puzzles.
     * One puzzle solved three times filled all three lines before (player feedback 2026-11-02).
     *
     * @return list<array{result: PeriodResult, attempts: int}>
     */
    public function highlights(int $limit): array
    {
        /** @var array<string, array{result: PeriodResult, attempts: int}> $groups */
        $groups = [];

        foreach ($this->countedResults() as $result) {
            $key = $result->puzzleId . '|' . $result->puzzlingType;
            $held = $groups[$key] ?? null;

            if ($held === null) {
                $groups[$key] = ['result' => $result, 'attempts' => 1];

                continue;
            }

            $groups[$key] = [
                'result' => self::faster($result, $held['result']) ? $result : $held['result'],
                'attempts' => $held['attempts'] + 1,
            ];
        }

        $highlights = array_values($groups);

        usort($highlights, static fn (array $a, array $b): int => [$b['result']->personalBest, $b['result']->firstAttempt, $b['result']->piecesCount, $b['result']->solvedAt] <=> [$a['result']->personalBest, $a['result']->firstAttempt, $a['result']->piecesCount, $a['result']->solvedAt]);

        return array_slice($highlights, 0, $limit);
    }

    /**
     * A timed result beats an untimed one; on equal times the earlier solve stays.
     */
    private static function faster(PeriodResult $candidate, PeriodResult $held): bool
    {
        if ($candidate->secondsToSolve === null) {
            return false;
        }

        if ($held->secondsToSolve === null) {
            return true;
        }

        return $candidate->secondsToSolve < $held->secondsToSolve
            || ($candidate->secondsToSolve === $held->secondsToSolve && $candidate->solvedAt < $held->solvedAt);
    }
}
