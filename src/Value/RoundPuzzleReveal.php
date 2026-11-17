<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateInterval;
use DateTimeImmutable;

/**
 * When a secret competition puzzle (CompetitionRoundPuzzle::$hideUntilRoundStarts) is revealed - the one moment
 * every surface obeys: the event pages, the API and, for a puzzle created for the round, the whole site
 * (puzzle.hide_until / hide_image_until - the latest reveal of the rounds keeping it secret, SecretPuzzleHides).
 *
 * - Automatic: the round's reveal delay (competition_round.reveal_delay_minutes, 10 unless the organiser set another)
 *   after the round starts - follows the round when its start or its delay changes.
 * - Scheduled: at the organiser's own moment (also what "Reveal now" leaves behind), never moved by the round.
 * - Manual: not before the organiser clicks "Reveal now" - no moment at all.
 * - Stopwatch: when the round's stopwatch starts with "Reveal the puzzles" ticked (StartRoundStopwatch /
 *   ScheduleRoundStart) - no moment until then, like a manual reveal; the start writes its moment into reveal_at
 *   (CompetitionRoundPuzzle::revealAtStopwatchStart()), a countdown called off takes a moment still ahead back
 *   (withdrawStopwatchReveal()). A start without the tick (a test run) reveals nothing; a stopwatch never started with
 *   it keeps the puzzle hidden until "Reveal now" - no fallback, a late reveal is harmless, an early one is not
 *   (docs/features/competitions-management/toolkit/A-round-formats.md §4).
 *
 * PHP (revealAt(), automaticRevealAt()) and SQL (sqlRevealAt(), sqlHidden()) compute the same moment; nothing else may
 * (RoundRevealMomentComputedOnlyHereTest). An unboxed round keeps the pictures back longer - sqlImageHidden().
 */
enum RoundPuzzleReveal: string
{
    case Automatic = 'automatic';
    case Scheduled = 'scheduled';
    case Manual = 'manual';
    case Stopwatch = 'stopwatch';

    /**
     * A new round's delay - also the column's default, so a round inserted by code that does not know the delay (an
     * older release during a blue-green deploy) gets it too.
     */
    public const int DEFAULT_DELAY_MINUTES = 10;

    /**
     * The longest round on record (minutes_limit 240) - "secret until the round is over" stays automatic for every round
     * we know. A later reveal is not tied to the round's run any more: a scheduled one.
     */
    public const int MAX_DELAY_MINUTES = 240;

    /**
     * Null = no moment yet (manual, or a stopwatch reveal before its start): hidden until the organiser reveals it.
     *
     * @param int $revealDelayMinutes the round's delay (CompetitionRound::$revealDelayMinutes) - only an automatic reveal
     *     reads it
     */
    public function revealAt(DateTimeImmutable $roundStartsAt, int $revealDelayMinutes, null|DateTimeImmutable $scheduledAt): null|DateTimeImmutable
    {
        return match ($this) {
            self::Automatic => self::automaticRevealAt($roundStartsAt, $revealDelayMinutes),
            self::Scheduled => $scheduledAt,
            self::Manual => null,
            // The moment the stopwatch start with the tick wrote - null until then
            self::Stopwatch => $scheduledAt,
        };
    }

    /**
     * The automatic reveal of a round starting at $roundStartsAt with this delay - what sqlRevealAt() computes in SQL.
     */
    public static function automaticRevealAt(DateTimeImmutable $roundStartsAt, int $revealDelayMinutes): DateTimeImmutable
    {
        if ($revealDelayMinutes < 0) {
            throw new \InvalidArgumentException(sprintf('A reveal delay cannot be negative, %d given.', $revealDelayMinutes));
        }

        return $roundStartsAt->add(new DateInterval(sprintf('PT%dM', $revealDelayMinutes)));
    }

    public static function isValidDelay(int $revealDelayMinutes): bool
    {
        return $revealDelayMinutes >= 0 && $revealDelayMinutes <= self::MAX_DELAY_MINUTES;
    }

    /**
     * The backstop of every writer (the entity, the handlers before they change anything) - the round form and the
     * internal API validate first.
     *
     * @throws \InvalidArgumentException
     */
    public static function assertValidDelay(int $revealDelayMinutes): void
    {
        if (self::isValidDelay($revealDelayMinutes) === false) {
            throw new \InvalidArgumentException(sprintf(
                'A reveal delay is whole minutes from 0 to %d, %d given.',
                self::MAX_DELAY_MINUTES,
                $revealDelayMinutes,
            ));
        }
    }

    /**
     * The reveal moment of a round puzzle row as SQL - NULL for a manual reveal (and a stopwatch reveal before its start).
     *
     * $roundAlias must be a real competition_round row (the round of $roundPuzzleAlias): the automatic moment reads its
     * starts_at and reveal_delay_minutes.
     */
    public static function sqlRevealAt(string $roundPuzzleAlias, string $roundAlias): string
    {
        $automatic = self::Automatic->value;
        $scheduled = self::Scheduled->value;
        $stopwatch = self::Stopwatch->value;

        return "(CASE {$roundPuzzleAlias}.reveal_mode"
            . " WHEN '{$automatic}' THEN {$roundAlias}.starts_at + make_interval(mins => {$roundAlias}.reveal_delay_minutes)"
            . " WHEN '{$scheduled}' THEN {$roundPuzzleAlias}.reveal_at"
            . " WHEN '{$stopwatch}' THEN {$roundPuzzleAlias}.reveal_at"
            . ' END)';
    }

    /**
     * True while the round still keeps the puzzle secret (entirely or its picture - see hide_mode) at :now.
     *
     * $roundAlias must be a real competition_round row - see sqlRevealAt().
     */
    public static function sqlHidden(string $roundPuzzleAlias, string $roundAlias, string $nowParameter = ':now'): string
    {
        $revealAt = self::sqlRevealAt($roundPuzzleAlias, $roundAlias);

        return "({$roundPuzzleAlias}.hide_until_round_starts"
            . " AND COALESCE({$revealAt}, 'infinity'::timestamp) > {$nowParameter}::timestamp)";
    }

    /**
     * True while the round keeps the puzzle's PICTURE back at :now: sqlHidden() (a secret not revealed yet, entirely or
     * its picture), or an unboxed round (competition_round.unboxed) whose results were never published - the picture
     * of every puzzle of such a round, secret or not, stays back until the first publish
     * (docs/features/competitions-management/world-series-rules.md §7; RoundPuzzlesVisibleNow is the PHP twin).
     * Whether the NAME is hidden stays sqlHidden() + the hide mode - unboxed never hides a name.
     *
     * $roundAlias must be a real competition_round row - see sqlRevealAt().
     */
    public static function sqlImageHidden(string $roundPuzzleAlias, string $roundAlias, string $nowParameter = ':now'): string
    {
        $hidden = self::sqlHidden($roundPuzzleAlias, $roundAlias, $nowParameter);

        return "({$hidden} OR ({$roundAlias}.unboxed AND {$roundAlias}.results_first_published_at IS NULL))";
    }
}
