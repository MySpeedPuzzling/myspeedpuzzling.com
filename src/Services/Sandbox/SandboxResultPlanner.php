<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Sandbox;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use Random\Engine\Mt19937;
use Random\Randomizer;
use SpeedPuzzling\Web\Value\SandboxPlannedResult;
use SpeedPuzzling\Web\Value\SandboxPlayer;
use SpeedPuzzling\Web\Value\SandboxPuzzle;

/**
 * Decides which results the sandbox's made-up players log (docs/features/sandbox/README.md "Data"): a history for the
 * seed and a few fresh results for the activity cron. It only plans - the commands log every planned result through
 * AddPuzzleSolvingTime, the add-time form's own message, so statistics, rankings, XP, predictions and feeds follow
 * exactly as they do for a person.
 *
 * Shaped like the real site, roughly: a few very active players and many occasional ones, mostly the piece count the
 * player prefers, a first try on most first solves, some repeats, some pairs and teams.
 */
final readonly class SandboxResultPlanner
{
    private const float PAIR_SHARE = 0.08;
    private const float TEAM_SHARE = 0.03;
    private const float REPEAT_SHARE = 0.12;
    private const float FIRST_TRY_SHARE = 0.8;

    public function __construct(
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param list<SandboxPlayer> $players
     * @param list<SandboxPuzzle> $puzzles
     * @return list<SandboxPlannedResult> in the order they are saved (trackedAt, oldest first)
     */
    public function history(array $players, array $puzzles, int $results, int $seed): array
    {
        if ($players === [] || $puzzles === [] || $results <= 0) {
            return [];
        }

        $randomizer = new Randomizer(new Mt19937($seed));
        $until = $this->clock->now();
        $puzzlesByPieces = self::byPieces($puzzles);

        $activities = array_map(
            static fn (SandboxPlayer $player): float => SandboxTimeModel::activity($player->id),
            $players,
        );
        $totalActivity = array_sum($activities);

        $planned = [];

        foreach ($players as $index => $player) {
            $count = max(3, (int) round($results * $activities[$index] / $totalActivity));
            $from = $player->registeredAt->getTimestamp();
            $span = max(1, $until->getTimestamp() - $from);

            $moments = [];

            for ($i = 0; $i < $count; $i++) {
                $moments[] = $from + $randomizer->getInt(0, $span);
            }

            sort($moments);

            /** @var array<string, int> $solved puzzle id => solves so far */
            $solved = [];

            foreach ($moments as $moment) {
                $finishedAt = DateTimeImmutable::createFromFormat('U', (string) $moment);
                assert($finishedAt !== false);
                $result = $this->planOne($randomizer, $player, $players, $puzzles, $puzzlesByPieces, $solved, $finishedAt, $until);
                $solved[$result->puzzle->id] = ($solved[$result->puzzle->id] ?? 0) + 1;
                $planned[] = $result;
            }
        }

        usort(
            $planned,
            static fn (SandboxPlannedResult $a, SandboxPlannedResult $b): int => [$a->trackedAt, $a->timeId->toString()] <=> [$b->trackedAt, $b->timeId->toString()],
        );

        return $planned;
    }

    /**
     * A few results finished in the last minutes, saved now - the activity cron (myspeedpuzzling:sandbox:simulate-activity).
     *
     * @param list<SandboxPlayer> $players
     * @param list<SandboxPuzzle> $puzzles
     * @param \Closure(string): array<string, int> $solvedByPlayer player id => (puzzle id => solves so far)
     * @return list<SandboxPlannedResult>
     */
    public function recent(array $players, array $puzzles, \Closure $solvedByPlayer, int $results, null|int $seed = null): array
    {
        if ($players === [] || $puzzles === [] || $results <= 0) {
            return [];
        }

        $randomizer = $seed === null ? new Randomizer() : new Randomizer(new Mt19937($seed));
        $now = $this->clock->now();
        $puzzlesByPieces = self::byPieces($puzzles);
        $weights = array_map(
            static fn (SandboxPlayer $player): float => SandboxTimeModel::activity($player->id),
            $players,
        );

        $planned = [];

        for ($i = 0; $i < $results; $i++) {
            $player = $players[self::weightedIndex($randomizer, $weights)];
            $solved = $solvedByPlayer($player->id);

            // Results planned in this run count too - two in one run never both claim the first try
            foreach ($planned as $earlier) {
                if ($earlier->player->id === $player->id) {
                    $solved[$earlier->puzzle->id] = ($solved[$earlier->puzzle->id] ?? 0) + 1;
                }
            }

            $finishedAt = $now->modify(sprintf('-%d minutes', $randomizer->getInt(1, 40)));
            $planned[] = $this->planOne($randomizer, $player, $players, $puzzles, $puzzlesByPieces, $solved, $finishedAt, $now, savedAt: $now);
        }

        return $planned;
    }

    /**
     * @param list<SandboxPlayer> $players
     * @param list<SandboxPuzzle> $puzzles
     * @param array<int, list<SandboxPuzzle>> $puzzlesByPieces
     * @param array<string, int> $solved
     */
    private function planOne(
        Randomizer $randomizer,
        SandboxPlayer $player,
        array $players,
        array $puzzles,
        array $puzzlesByPieces,
        array $solved,
        DateTimeImmutable $finishedAt,
        DateTimeImmutable $until,
        null|DateTimeImmutable $savedAt = null,
    ): SandboxPlannedResult {
        $puzzle = $this->pickPuzzle($randomizer, $player, $puzzles, $puzzlesByPieces, $solved);
        $attempt = ($solved[$puzzle->id] ?? 0) + 1;

        $groupPlayers = [];
        $roll = $randomizer->nextFloat();

        if ($roll < self::PAIR_SHARE + self::TEAM_SHARE) {
            $size = $roll < self::PAIR_SHARE ? 2 : $randomizer->getInt(3, 4);
            $groupPlayers = $this->pickCoPuzzlers($randomizer, $player, $players, $finishedAt, $size - 1);
        }

        $puzzlers = 1 + count($groupPlayers);
        // A pair/team result never claims the first try: it would have to be everybody's (docs/features/first-try-integrity.md)
        $firstAttempt = $puzzlers === 1 && $attempt === 1 && $randomizer->nextFloat() < self::FIRST_TRY_SHARE;

        $trackedAt = $savedAt ?? $finishedAt->modify(sprintf('+%d minutes', $randomizer->getInt(2, 360)));

        if ($trackedAt > $until) {
            $trackedAt = $until;
        }

        return new SandboxPlannedResult(
            timeId: Uuid::uuid7($trackedAt),
            player: $player,
            puzzle: $puzzle,
            seconds: SandboxTimeModel::seconds($randomizer, $player->id, $puzzle->id, $puzzle->piecesCount, $attempt, $puzzlers),
            finishedAt: $finishedAt,
            trackedAt: $trackedAt,
            firstAttempt: $firstAttempt,
            unboxed: $firstAttempt && $randomizer->nextFloat() < 0.35,
            groupPlayers: $groupPlayers,
        );
    }

    /**
     * @param list<SandboxPuzzle> $puzzles
     * @param array<int, list<SandboxPuzzle>> $puzzlesByPieces
     * @param array<string, int> $solved
     */
    private function pickPuzzle(Randomizer $randomizer, SandboxPlayer $player, array $puzzles, array $puzzlesByPieces, array $solved): SandboxPuzzle
    {
        if ($solved !== [] && $randomizer->nextFloat() < self::REPEAT_SHARE) {
            $solvedIds = array_keys($solved);
            $repeatId = $solvedIds[$randomizer->getInt(0, count($solvedIds) - 1)];

            foreach ($puzzles as $puzzle) {
                if ($puzzle->id === $repeatId) {
                    return $puzzle;
                }
            }
        }

        $preferred = $puzzlesByPieces[SandboxTimeModel::preferredPieces($player->id)] ?? [];
        $pool = $preferred !== [] && $randomizer->nextFloat() < 0.8 ? $preferred : $puzzles;

        // A new puzzle if one turns up within a few draws - a heavy player may have solved most of a small pool
        $candidate = $pool[$randomizer->getInt(0, count($pool) - 1)];

        for ($try = 0; $try < 5 && isset($solved[$candidate->id]); $try++) {
            $candidate = $pool[$randomizer->getInt(0, count($pool) - 1)];
        }

        return $candidate;
    }

    /**
     * Other generated players who were registered when the puzzle was finished, as "#CODE"
     *
     * @param list<SandboxPlayer> $players
     * @return list<string>
     */
    private function pickCoPuzzlers(Randomizer $randomizer, SandboxPlayer $player, array $players, DateTimeImmutable $finishedAt, int $count): array
    {
        $candidates = array_values(array_filter(
            $players,
            static fn (SandboxPlayer $other): bool => $other->id !== $player->id && $other->registeredAt <= $finishedAt,
        ));

        if ($candidates === []) {
            return [];
        }

        $codes = [];

        foreach (array_slice($randomizer->shuffleArray($candidates), 0, $count) as $other) {
            assert($other instanceof SandboxPlayer);
            $codes[] = '#' . $other->code;
        }

        return $codes;
    }

    /**
     * @param list<SandboxPuzzle> $puzzles
     * @return array<int, list<SandboxPuzzle>>
     */
    private static function byPieces(array $puzzles): array
    {
        $byPieces = [];

        foreach ($puzzles as $puzzle) {
            $byPieces[$puzzle->piecesCount][] = $puzzle;
        }

        return $byPieces;
    }

    /**
     * @param list<float> $weights
     */
    private static function weightedIndex(Randomizer $randomizer, array $weights): int
    {
        $target = $randomizer->nextFloat() * array_sum($weights);

        foreach ($weights as $index => $weight) {
            $target -= $weight;

            if ($target < 0) {
                return $index;
            }
        }

        return array_key_last($weights) ?? 0;
    }
}
