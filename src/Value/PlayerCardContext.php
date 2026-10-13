<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The validated `context` of a player card request (docs/features/navigation CARD-02): `<kind>:<uuid>` for a puzzle, a
 * feed result, a notification's result or an event round, `leaderboard:<board>[:<country>]` for a ladder. Anything
 * else is no context - the card renders without one, never a 404 (tryFrom() answers null).
 *
 * The card keeps it on its own links (Favorite's `return`, the data-nav ids), so it travels with the card.
 */
readonly final class PlayerCardContext
{
    private const string UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';

    private function __construct(
        public PlayerCardContextKind $kind,
        // The uuid of the puzzle / time / round, or the leaderboard's board[:country]
        public string $subject,
    ) {
    }

    public static function tryFrom(mixed $value): null|self
    {
        if (!is_string($value) || strlen($value) > 64) {
            return null;
        }

        if (preg_match('~^(puzzle|feed|notification|round):(' . self::UUID . ')$~', $value, $match) === 1) {
            return new self(PlayerCardContextKind::from($match[1]), $match[2]);
        }

        if (preg_match('~^leaderboard:((?:times-\d{2,5}|msp-\d{2,5}|xp|ap|karma)(?::[a-z]{2})?)$~', $value, $match) === 1) {
            return new self(PlayerCardContextKind::Leaderboard, $match[1]);
        }

        return null;
    }

    public function toString(): string
    {
        return $this->kind->value . ':' . $this->subject;
    }
}
