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
 *
 * PHP (revealAt(), automaticRevealAt()) and SQL (sqlRevealAt(), sqlHidden()) compute the same moment; nothing else may
 * (RoundRevealMomentComputedOnlyHereTest).
 */
enum RoundPuzzleReveal: string
{
    case Automatic = 'automatic';
    case Scheduled = 'scheduled';
    case Manual = 'manual';

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
     * Null = no moment yet (manual): hidden until the organiser reveals it.
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
     * The reveal moment of a round puzzle row as SQL - NULL for a manual reveal.
     *
     * $roundAlias must be a real competition_round row (the round of $roundPuzzleAlias): the automatic moment reads its
     * starts_at and reveal_delay_minutes.
     */
    public static function sqlRevealAt(string $roundPuzzleAlias, string $roundAlias): string
    {
        $automatic = self::Automatic->value;
        $scheduled = self::Scheduled->value;

        return "(CASE {$roundPuzzleAlias}.reveal_mode"
            . " WHEN '{$automatic}' THEN {$roundAlias}.starts_at + make_interval(mins => {$roundAlias}.reveal_delay_minutes)"
            . " WHEN '{$scheduled}' THEN {$roundPuzzleAlias}.reveal_at"
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
}
