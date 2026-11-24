<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Sandbox;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Results\RoundResultEntry;
use SpeedPuzzling\Web\Value\RoundEntryPuzzle;
use SpeedPuzzling\Web\Value\RoundEntryPuzzles;
use SpeedPuzzling\Web\Value\RoundEntryResult;
use SpeedPuzzling\Web\Value\RoundResultChange;
use SpeedPuzzling\Web\Value\RoundResultField;
use SpeedPuzzling\Web\Value\SandboxDemoRound;

/**
 * Made-up official results of a demo round (docs/features/sandbox/README.md "Demo events") as the changes a judge's
 * device would send (RecordRoundResults). Deterministic: an entrant's speed comes from its name, so a pair is fast in
 * every round and a re-run plans the same. Only entries without anything recorded get changes - a re-run, or a result
 * typed during a rehearsal, is never overwritten.
 *
 * A published round: everybody has a result - the last entrant (by name) did not start (the no-show), the one before
 * it ran out of time with pieces missing, the 3rd and 6th finished with 1 and 2 pieces missing, slow ones time out on
 * their own; a round with `tie` gives the 5th exactly the 4th's result. A running round: the first third finished, on a block of challenges the next third has only its first
 * challenge's split, the rest nothing yet.
 */
final readonly class SandboxDemoResultPlanner
{
    /**
     * @param list<RoundResultEntry> $entries
     * @param list<array{id: string, pieces: int}> $roundPuzzles in the round's order
     * @return list<RoundResultChange>
     */
    public static function changes(SandboxDemoRound $round, array $entries, array $roundPuzzles): array
    {
        if ($round->state === SandboxDemoRound::PLANNED || $entries === []) {
            return [];
        }

        usort($entries, static fn (RoundResultEntry $a, RoundResultEntry $b): int => strcmp((string) $a->name, (string) $b->name) ?: strcmp($a->ref->toString(), $b->ref->toString()));
        $pick = $round->solveAll && count($roundPuzzles) >= 2;
        $count = count($entries);
        $changes = [];

        foreach ($entries as $index => $entry) {
            if ($entry->result->isNone() === false || $entry->puzzles !== null) {
                continue;
            }

            // A tie (SPWS demo): the 5th by name solves exactly like the 4th - a shared place
            $tied = $round->tie && $round->state === SandboxDemoRound::PUBLISHED && $index === 4 && $count >= 8;
            $plan = self::plan($round, $tied ? $entries[3] : $entry, $tied ? 3 : $index, $count, $pick ? $roundPuzzles : array_slice($roundPuzzles, 0, 1), $pick);

            foreach ($plan as $field => $value) {
                $changes[] = RoundResultChange::forEntry(
                    Uuid::uuid7()->toString(),
                    $entry->ref,
                    RoundResultField::from($field),
                    $field === RoundResultField::Result->value ? RoundEntryResult::none() : null,
                    $value,
                );
            }
        }

        return $changes;
    }

    /**
     * @param list<array{id: string, pieces: int}> $puzzles
     * @return array<string, RoundEntryResult|RoundEntryPuzzles|int> field => value, in the order they are sent
     */
    private static function plan(SandboxDemoRound $round, RoundResultEntry $entry, int $index, int $count, array $puzzles, bool $pick): array
    {
        $name = (string) $entry->name;
        $limit = $round->minutesLimit * 60;
        $published = $round->state === SandboxDemoRound::PUBLISHED;

        if ($published && $index === $count - 1) {
            return ['result' => RoundEntryResult::didNotStart()];
        }

        if ($published === false) {
            $finishedShare = intdiv($count, 3);

            if ($index >= ($pick ? 2 * $finishedShare : $finishedShare)) {
                return [];
            }
        }

        // 0.72-1.32: the entrant's speed, the same in every round; 0.93-1.07: this round's day
        $speed = 0.72 + 0.6 * self::unit($name);
        $splits = [];
        $elapsed = 0;

        foreach ($puzzles === [] ? [['id' => '', 'pieces' => 500]] : $puzzles as $position => $puzzle) {
            $elapsed += max(60, (int) round($round->baseSeconds * $speed * (0.93 + 0.14 * self::unit($name . '|' . $round->key . '|' . $position))));
            $splits[] = $elapsed;
        }

        $timesUp = ($published && $index === $count - 2) || $elapsed > $limit - 20;

        // A block running: the middle third has only its first challenge
        if ($published === false && $pick && $index >= intdiv($count, 3)) {
            $firstSplit = $splits[0];
            $firstPuzzle = $puzzles[0]['id'] ?? null;

            if ($firstPuzzle === null || $firstSplit >= $limit - 20) {
                return [];
            }

            return ['puzzles' => self::record([new RoundEntryPuzzle($firstPuzzle, true, $firstSplit)])];
        }

        if ($timesUp) {
            return self::timesUp($puzzles, $splits, $limit, $pick, $name);
        }

        $plan = ['result' => RoundEntryResult::finished($elapsed)];

        if ($pick) {
            $items = [];

            foreach ($puzzles as $position => $puzzle) {
                $items[] = new RoundEntryPuzzle($puzzle['id'], true, $splits[$position] ?? $elapsed);
            }

            $plan['puzzles'] = self::record($items);
        }

        if ($published && ($index === 2 || $index === 5)) {
            $plan['missing_pieces'] = $index === 2 ? 1 : 2;
        }

        return $plan;
    }

    /**
     * Time ran out: the puzzles finished before the limit are done, the first one that was not has most of its pieces.
     *
     * @param list<array{id: string, pieces: int}> $puzzles
     * @param list<int> $splits
     * @return array<string, RoundEntryResult|RoundEntryPuzzles>
     */
    private static function timesUp(array $puzzles, array $splits, int $limit, bool $pick, string $name): array
    {
        $share = 0.72 + 0.25 * self::unit($name . '|placed');

        if ($pick === false) {
            $pieces = $puzzles[0]['pieces'] ?? 500;

            return ['result' => RoundEntryResult::unfinished(max(1, min($pieces - 1, (int) floor($pieces * $share))))];
        }

        $items = [];
        $placed = 0;

        foreach ($puzzles as $position => $puzzle) {
            // Finished well within the limit and not the last one - done
            $split = $splits[$position] ?? $limit;

            if ($split < $limit - 60 && $position < count($puzzles) - 1) {
                $items[] = new RoundEntryPuzzle($puzzle['id'], true, $split);
                $placed += $puzzle['pieces'];

                continue;
            }

            $onIt = max(1, min($puzzle['pieces'] - 1, (int) floor($puzzle['pieces'] * $share)));
            $items[] = new RoundEntryPuzzle($puzzle['id'], false, piecesPlaced: $onIt);
            $placed += $onIt;

            break;
        }

        return ['result' => RoundEntryResult::unfinished($placed), 'puzzles' => self::record($items)];
    }

    /**
     * @param list<RoundEntryPuzzle> $items
     */
    private static function record(array $items): RoundEntryPuzzles
    {
        $record = RoundEntryPuzzles::of($items);
        assert($record !== null);

        return $record;
    }

    /**
     * 0..1 from a string - the same string, the same number
     */
    public static function unit(string $seed): float
    {
        return hexdec(substr(md5($seed), 0, 7)) / 0xFFFFFFF;
    }
}
