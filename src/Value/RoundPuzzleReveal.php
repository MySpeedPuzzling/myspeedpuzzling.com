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
 * - Automatic: 10 minutes after the round starts, follows the round when its start moves.
 * - Scheduled: at the organiser's own moment (also what "Reveal now" leaves behind), never moved by the round.
 * - Manual: not before the organiser clicks "Reveal now" - no moment at all.
 *
 * PHP (revealAt()) and SQL (sqlRevealAt(), sqlHidden()) compute the same moment; nothing else may.
 */
enum RoundPuzzleReveal: string
{
    case Automatic = 'automatic';
    case Scheduled = 'scheduled';
    case Manual = 'manual';

    public const string AUTOMATIC_DELAY = 'PT10M';

    public const int AUTOMATIC_DELAY_MINUTES = 10;

    /**
     * Null = no moment yet (manual): hidden until the organiser reveals it.
     */
    public function revealAt(DateTimeImmutable $roundStartsAt, null|DateTimeImmutable $scheduledAt): null|DateTimeImmutable
    {
        return match ($this) {
            self::Automatic => $roundStartsAt->add(new DateInterval(self::AUTOMATIC_DELAY)),
            self::Scheduled => $scheduledAt,
            self::Manual => null,
        };
    }

    /**
     * The reveal moment of a round puzzle row as SQL - NULL for a manual reveal.
     */
    public static function sqlRevealAt(string $roundPuzzleAlias, string $roundAlias): string
    {
        $automatic = self::Automatic->value;
        $scheduled = self::Scheduled->value;
        $minutes = self::AUTOMATIC_DELAY_MINUTES;

        return "(CASE {$roundPuzzleAlias}.reveal_mode"
            . " WHEN '{$automatic}' THEN {$roundAlias}.starts_at + INTERVAL '{$minutes} minutes'"
            . " WHEN '{$scheduled}' THEN {$roundPuzzleAlias}.reveal_at"
            . ' END)';
    }

    /**
     * True while the round still keeps the puzzle secret (entirely or its picture - see hide_mode) at :now.
     */
    public static function sqlHidden(string $roundPuzzleAlias, string $roundAlias, string $nowParameter = ':now'): string
    {
        $revealAt = self::sqlRevealAt($roundPuzzleAlias, $roundAlias);

        return "({$roundPuzzleAlias}.hide_until_round_starts"
            . " AND COALESCE({$revealAt}, 'infinity'::timestamp) > {$nowParameter}::timestamp)";
    }
}
