<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

/**
 * What the organiser is told about one puzzle of their round - its EFFECTIVE visibility, never just this round's own
 * setting: the round's reveal (event page) combined with the puzzle's site-wide hide (puzzle.hide_until /
 * hide_image_until, which other rounds keeping it secret may hold longer). "Revealed" only when nothing hides it on
 * this round's event page any more; "already public elsewhere" only when the site does not hide it.
 */
final readonly class RoundPuzzleStatus
{
    public const string NOT_SECRET = 'not_secret';
    public const string REVEALED = 'revealed';
    public const string HIDDEN = 'hidden';
    public const string IMAGE_HIDDEN = 'image_hidden';

    private function __construct(
        // One of the constants
        public string $kind,
        // Until when (hidden kinds: the moment it comes out; revealed: when it came out); null = no moment
        public null|DateTimeImmutable $until,
        // The whole site hides what this status says, not only this round's event page
        public bool $everywhere,
        // No moment yet because THIS round waits for "Reveal now"
        public bool $waitsForThisRound,
        // Another round keeps it hidden longer than this round would
        public bool $heldByAnotherRound,
    ) {
    }

    /**
     * @param null|DateTimeImmutable $roundRevealsAt this round's moment, null = manual (only read when $secretInRound)
     */
    public static function of(
        bool $secretInRound,
        null|PuzzleHideMode $hideMode,
        null|DateTimeImmutable $roundRevealsAt,
        null|DateTimeImmutable $puzzleHiddenUntil,
        null|DateTimeImmutable $puzzleImageHiddenUntil,
        DateTimeImmutable $now,
    ): self {
        $never = new DateTimeImmutable('9999-12-31 00:00:00');
        $roundMoment = $secretInRound ? ($roundRevealsAt ?? $never) : null;
        $roundHidesName = $secretInRound && ($hideMode ?? PuzzleHideMode::Entirely) === PuzzleHideMode::Entirely;

        $siteName = $puzzleHiddenUntil !== null && $puzzleHiddenUntil > $now ? $puzzleHiddenUntil : null;
        $siteImage = self::latest($puzzleImageHiddenUntil !== null && $puzzleImageHiddenUntil > $now ? $puzzleImageHiddenUntil : null, $siteName);

        $roundName = $roundHidesName && $roundMoment !== null && $roundMoment > $now ? $roundMoment : null;
        $roundImage = $roundMoment !== null && $roundMoment > $now ? $roundMoment : null;

        $nameUntil = self::latest($roundName, $siteName);
        $imageUntil = self::latest($roundImage, $siteImage, $nameUntil);

        if ($imageUntil === null) {
            $past = self::latest($roundMoment, $puzzleHiddenUntil, $puzzleImageHiddenUntil);

            return $secretInRound || $past !== null
                ? new self(self::REVEALED, $past, false, false, false)
                : new self(self::NOT_SECRET, null, false, false, false);
        }

        $kind = $nameUntil !== null ? self::HIDDEN : self::IMAGE_HIDDEN;
        $until = $nameUntil ?? $imageUntil;
        $everywhere = $kind === self::HIDDEN ? $siteName !== null : $siteImage !== null;
        $isNever = $until >= $never;
        $roundOwn = $kind === self::HIDDEN ? $roundName : $roundImage;

        return new self(
            kind: $kind,
            until: $isNever ? null : $until,
            everywhere: $everywhere,
            waitsForThisRound: $isNever && $roundOwn !== null && $roundOwn >= $never,
            heldByAnotherRound: $roundOwn === null || $until > $roundOwn,
        );
    }

    private static function latest(null|DateTimeImmutable ...$moments): null|DateTimeImmutable
    {
        $latest = null;

        foreach ($moments as $moment) {
            if ($moment !== null && ($latest === null || $moment > $latest)) {
                $latest = $moment;
            }
        }

        return $latest;
    }
}
