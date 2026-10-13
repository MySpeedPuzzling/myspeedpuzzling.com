<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The board of a player card's leaderboard context (docs/features/navigation CARD-02): `times-<pieces>` (the fastest
 * solo times of a piece count), `msp-<pieces>` (the MSP Rating ladder), `xp`, `ap`, `karma` (the progression boards),
 * each optionally narrowed to a country (`:<cc>`) - the list on screen the card was opened from. Only these boards get
 * a context: the pair and team boards rank pairs/teams, not the person the card is about (decision 73).
 */
readonly final class PlayerCardBoard
{
    public const string TIMES = 'times';
    public const string MSP = 'msp';
    public const string XP = 'xp';
    public const string ACHIEVEMENT_POINTS = 'ap';
    public const string KARMA = 'karma';

    private function __construct(
        public string $kind,
        // The piece count of a Times or MSP Rating board, null for the progression boards
        public null|int $piecesCount,
        public null|CountryCode $country,
    ) {
    }

    /**
     * From PlayerCardContext::$subject of a leaderboard context; null for an unknown board or country
     */
    public static function tryFromSubject(string $subject): null|self
    {
        if (preg_match('~^(?:(times|msp)-(\d{2,5})|(xp|ap|karma))(?::([a-z]{2}))?$~', $subject, $match) !== 1) {
            return null;
        }

        $country = null;

        if (($match[4] ?? '') !== '') {
            $country = CountryCode::fromCode($match[4]);

            if ($country === null) {
                return null;
            }
        }

        $timedKind = $match[1] ?? '';

        if ($timedKind !== '') {
            return new self($timedKind, (int) ($match[2] ?? 0), $country);
        }

        return new self($match[3] ?? '', null, $country);
    }

    public function progressTab(): null|ProgressLeaderboardTab
    {
        return match ($this->kind) {
            self::XP => ProgressLeaderboardTab::Xp,
            self::ACHIEVEMENT_POINTS => ProgressLeaderboardTab::AchievementPoints,
            self::KARMA => ProgressLeaderboardTab::Karma,
            default => null,
        };
    }

    /**
     * The context string of a progression board (LadderTable / MspRatingLadder use contextOf())
     */
    public static function contextOfProgressTab(ProgressLeaderboardTab $tab, null|string $country): string
    {
        return self::contextOf(match ($tab) {
            ProgressLeaderboardTab::Xp => self::XP,
            ProgressLeaderboardTab::AchievementPoints => self::ACHIEVEMENT_POINTS,
            ProgressLeaderboardTab::Karma => self::KARMA,
        }, country: $country);
    }

    /**
     * The context string of this board, for the triggers' data-player-card-context (PlayerCardContext::tryFrom() reads
     * it back)
     */
    public static function contextOf(string $kind, null|int $piecesCount = null, null|string $country = null): string
    {
        $board = $piecesCount !== null ? $kind . '-' . $piecesCount : $kind;
        $country = $country !== null ? CountryCode::fromCode($country) : null;

        return 'leaderboard:' . $board . ($country !== null ? ':' . $country->name : '');
    }
}
