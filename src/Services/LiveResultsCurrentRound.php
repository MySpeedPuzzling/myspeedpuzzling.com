<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\RoundResultsOverview;

/**
 * The round a referee most likely enters results for right now (docs/features/competitions-management/live-results.md):
 * the one whose stopwatch runs (the latest started when several run - parallel halls), else the one started last
 * within the past 12 hours (late results of the round that just ended), else the next one, else the last one.
 * "Started" = its stopwatch's start, or its scheduled start once that has passed (rounds timed without the stopwatch).
 */
final readonly class LiveResultsCurrentRound
{
    public const string RECENTLY_STARTED = '-12 hours';

    /**
     * @param list<RoundResultsOverview> $rounds
     */
    public static function pick(array $rounds, DateTimeImmutable $now): null|RoundResultsOverview
    {
        if ($rounds === []) {
            return null;
        }

        $running = array_filter($rounds, static fn (RoundResultsOverview $round): bool => $round->stopwatchStatus === 'running');

        if ($running !== []) {
            return self::latestStarted(array_values($running), $now);
        }

        $recentFrom = $now->modify(self::RECENTLY_STARTED);
        $recent = array_filter($rounds, static function (RoundResultsOverview $round) use ($now, $recentFrom): bool {
            $startedAt = self::startedAt($round, $now);

            return $startedAt !== null && $startedAt >= $recentFrom;
        });

        if ($recent !== []) {
            return self::latestStarted(array_values($recent), $now);
        }

        $upcoming = array_values(array_filter($rounds, static fn (RoundResultsOverview $round): bool => $round->startsAt > $now));

        if ($upcoming !== []) {
            usort($upcoming, static fn (RoundResultsOverview $a, RoundResultsOverview $b): int => [$a->startsAt, $a->name] <=> [$b->startsAt, $b->name]);

            return $upcoming[0];
        }

        $all = $rounds;
        usort($all, static fn (RoundResultsOverview $a, RoundResultsOverview $b): int => [$b->startsAt, $b->name] <=> [$a->startsAt, $a->name]);

        return $all[0];
    }

    /**
     * @param non-empty-list<RoundResultsOverview> $rounds
     */
    private static function latestStarted(array $rounds, DateTimeImmutable $now): RoundResultsOverview
    {
        usort($rounds, static function (RoundResultsOverview $a, RoundResultsOverview $b) use ($now): int {
            return [self::startedAt($b, $now) ?? $b->startsAt, $b->startsAt, $a->name]
                <=> [self::startedAt($a, $now) ?? $a->startsAt, $a->startsAt, $b->name];
        });

        return $rounds[0];
    }

    private static function startedAt(RoundResultsOverview $round, DateTimeImmutable $now): null|DateTimeImmutable
    {
        if ($round->stopwatchStartedAt !== null && $round->stopwatchStartedAt <= $now) {
            return $round->stopwatchStartedAt;
        }

        if ($round->startsAt <= $now) {
            return $round->startsAt;
        }

        return null;
    }
}
