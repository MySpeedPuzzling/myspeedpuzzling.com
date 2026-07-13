<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Xp;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Query\GetPuzzleSpeedPercentiles;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Results\PuzzleSpeedPercentiles;
use SpeedPuzzling\Web\Value\LevelTable;
use SpeedPuzzling\Web\Value\SolveXpContext;
use SpeedPuzzling\Web\Value\SpeedPercentile;
use SpeedPuzzling\Web\Value\XpEntryDraft;
use SpeedPuzzling\Web\Value\XpReason;

/**
 * Deterministic full-ledger rebuild for one player: wipes all solve-derived entries
 * (achievement entries are preserved — never revoked), replays the complete solve history
 * in canonical order (COALESCE(finished_at, tracked_at), id) and restores Player totals.
 *
 * Running it twice in a row yields an identical ledger — difficulty tiers and speed medians
 * are read as of the run ("tier at backfill run time, settled immediately, frozen forever").
 * Suspicious solves earn no XP, mirroring every other derived system (badges, statistics,
 * intelligence).
 */
readonly final class XpRecomputer
{
    public function __construct(
        private Connection $database,
        private PlayerRepository $playerRepository,
        private XpCalculator $xpCalculator,
        private XpLedger $xpLedger,
        private GetPuzzleSpeedPercentiles $getPuzzleSpeedPercentiles,
        private LoggerInterface $logger,
    ) {
    }

    public function recomputeForPlayer(string $playerId): void
    {
        try {
            $player = $this->playerRepository->get($playerId);
        } catch (PlayerNotFound) {
            return;
        }

        // Wipe everything solve-derived (incl. settlements and compensations); the
        // doctrine_transaction middleware makes wipe + rebuild atomic.
        $this->database->executeStatement(
            'DELETE FROM xp_entry WHERE player_id = :playerId AND reason != :achievement',
            ['playerId' => $playerId, 'achievement' => XpReason::Achievement->value],
        );

        $solves = $this->loadSolveHistory($playerId);

        $puzzleIds = array_values(array_unique(array_column($solves, 'puzzle_id')));
        $percentilesByPuzzle = $this->getPuzzleSpeedPercentiles->forPuzzles($puzzleIds);

        $cutoff = XpCalculator::fullFormulaFrom();
        $drafts = [];

        /** @var array<string, int> $occurrenceByPuzzle */
        $occurrenceByPuzzle = [];
        /** @var array<string, int> $weeklyCounts */
        $weeklyCounts = [];
        /** @var array<string, true> $daysWithXp */
        $daysWithXp = [];

        foreach ($solves as $solve) {
            $occurrence = ($occurrenceByPuzzle[$solve['puzzle_id']] ?? 0) + 1;
            $occurrenceByPuzzle[$solve['puzzle_id']] = $occurrence;

            $earnedAt = new DateTimeImmutable($solve['earned_at']);
            $isBackfill = new DateTimeImmutable($solve['tracked_at']) < $cutoff;
            $isTimed = $solve['seconds_to_solve'] !== null;
            $isTeamOrDuo = $solve['puzzling_type'] !== 'solo';
            $weekKey = $earnedAt->format('o-\WW');
            $dayKey = $earnedAt->format('Y-m-d');

            $speedPercentile = SpeedPercentile::None;

            if ($isBackfill === false && $isTeamOrDuo === false && $solve['seconds_to_solve'] !== null) {
                $speedPercentile = $this->resolveSpeedPercentile(
                    percentiles: $percentilesByPuzzle[$solve['puzzle_id']] ?? PuzzleSpeedPercentiles::empty(),
                    piecesCount: $solve['pieces'],
                    secondsToSolve: $solve['seconds_to_solve'],
                    solvingTimeId: $solve['id'],
                    playerId: $playerId,
                );
            }

            $awards = $this->xpCalculator->calculate(new SolveXpContext(
                piecesCount: $solve['pieces'],
                difficultyTier: $solve['difficulty_tier'],
                isTimed: $isTimed,
                isTeamOrDuo: $isTeamOrDuo,
                unboxed: $solve['unboxed'],
                occurrenceIndex: $occurrence,
                isBackfill: $isBackfill,
                speedPercentile: $speedPercentile,
                xpEarningSolvesThisWeek: $weeklyCounts[$weekKey] ?? 0,
                isFirstXpEarningSolveOfDay: isset($daysWithXp[$dayKey]) === false,
            ));

            if ($awards === []) {
                continue;
            }

            $weeklyCounts[$weekKey] = ($weeklyCounts[$weekKey] ?? 0) + 1;
            $daysWithXp[$dayKey] = true;

            foreach ($awards as $award) {
                $drafts[] = new XpEntryDraft(
                    reason: $award->reason,
                    amount: $award->amount,
                    earnedAt: $earnedAt,
                    inWeeklyDelta: true,
                    solvingTimeId: Uuid::fromString($solve['id']),
                );
            }
        }

        // Reset totals to the preserved achievement entries, then let the ledger append
        // the rebuilt solve entries on top — xpTotal ends as SUM(all entries) again.
        $achievementTotal = $this->preservedAchievementTotal($playerId);
        $player->updateExperience($achievementTotal, LevelTable::levelForXp($achievementTotal));

        $this->xpLedger->append($player, $drafts);
    }

    /**
     * @return list<array{id: string, puzzle_id: string, seconds_to_solve: null|int, puzzling_type: string, unboxed: bool, pieces: int, difficulty_tier: null|int, tracked_at: string, earned_at: string}>
     */
    private function loadSolveHistory(string $playerId): array
    {
        $sql = <<<SQL
SELECT
    pst.id,
    pst.puzzle_id,
    pst.seconds_to_solve,
    pst.puzzling_type,
    pst.unboxed,
    COALESCE(pst.pieces_count_snapshot, p.pieces_count) AS pieces,
    pd.difficulty_tier,
    pst.tracked_at,
    COALESCE(pst.finished_at, pst.tracked_at) AS earned_at
FROM puzzle_solving_time pst
JOIN puzzle p ON p.id = pst.puzzle_id
LEFT JOIN puzzle_difficulty pd ON pd.puzzle_id = pst.puzzle_id
WHERE pst.suspicious = false
  AND (
    pst.player_id = :playerId
    OR (pst.team::jsonb -> 'puzzlers') @> jsonb_build_array(jsonb_build_object('player_id', CAST(:playerId AS UUID)))
  )
ORDER BY COALESCE(pst.finished_at, pst.tracked_at), pst.id
SQL;

        /** @var list<array{id: string, puzzle_id: string, seconds_to_solve: null|int, puzzling_type: string, unboxed: bool, pieces: int, difficulty_tier: null|int, tracked_at: string, earned_at: string}> $rows */
        $rows = $this->database->executeQuery($sql, ['playerId' => $playerId])->fetchAllAssociative();

        return $rows;
    }

    private function resolveSpeedPercentile(
        PuzzleSpeedPercentiles $percentiles,
        int $piecesCount,
        int $secondsToSolve,
        string $solvingTimeId,
        string $playerId,
    ): SpeedPercentile {
        if (XpCalculator::isImplausiblyFast($piecesCount, $secondsToSolve)) {
            // Silent guard: no speed bonus, no user-facing accusation — just ops visibility.
            $this->logger->warning('XP speed bonus denied by plausibility guard', [
                'solvingTimeId' => $solvingTimeId,
                'playerId' => $playerId,
                'piecesCount' => $piecesCount,
                'secondsToSolve' => $secondsToSolve,
            ]);

            return SpeedPercentile::None;
        }

        return $percentiles->percentileFor($secondsToSolve);
    }

    private function preservedAchievementTotal(string $playerId): int
    {
        $value = $this->database
            ->executeQuery(
                'SELECT COALESCE(SUM(amount), 0) FROM xp_entry WHERE player_id = :playerId',
                ['playerId' => $playerId],
            )
            ->fetchOne();

        return is_numeric($value) ? (int) $value : 0;
    }
}
