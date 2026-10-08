<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The colours a competition round is shown in - its name pill on event, edition and round result pages and its
 * filter chip in the participants list, so one round looks the same everywhere.
 *
 * - Background: the organiser's colour, else a distinct palette colour by the round's position in the schedule.
 *   The round form pre-fills #fe696a, so that value says nothing about the round and counts as "not chosen" -
 *   otherwise every round of most events would share it.
 * - Text: black or white, whichever reads better on the background by APCA (the larger absolute lightness contrast
 *   Lc, SAPC/APCA 0.0.98G-4g). Not WCAG 2's luminance ratio: it picks black on saturated mid-tones like #3d6cf2,
 *   where white is clearly more legible. Never the organiser's text colour: production had white on #ffc107. The
 *   round form no longer asks for one; `competition_round.badge_text_color` only keeps what textForChosen() gives
 *   (or what the internal API sends), nothing reads it for display.
 * - Every palette colour reaches |Lc| >= 60 with its text colour and 4.5:1 by WCAG 2 too (RoundBadgeColorTest). In
 *   2026-10 the green and orange were lightened (#3cb44b, #f58231 had Lc 57 / 55) and the blue, brown, olive and teal
 *   darkened a little (#0082c8, #aa6e28, #808000, #469990 had 4.2-3.4:1 with white).
 *
 * assets/round_badge_color.js does the same for the round form's live preview - keep the two in step
 * (RoundBadgeColorParityTest runs both).
 */
final class RoundBadgeColor
{
    public const array PALETTE = [
        '#e6194b', '#5fd36e', '#ffe119', '#007abb', '#ffa057', '#911eb4', '#46f0f0', '#d2f53c', '#fabebe', '#008080',
        '#e6beff', '#a06726', '#800000', '#000075', '#787800', '#000000', '#9a6324', '#3a7f77', '#fffac8', '#dcbeff',
    ];

    // What the round form pre-filled until 2026-10 - stored on most rounds, it says nothing about the round
    public const string ROUND_FORM_DEFAULT = '#fe696a';

    public static function background(null|string $chosenColor, int $schedulePosition): string
    {
        return self::chosen($chosenColor) ?? self::automatic($schedulePosition);
    }

    /**
     * The organiser's colour as lowercase #rrggbb, null when the round has none - nothing, not a hex colour, or the
     * old form default.
     */
    public static function chosen(null|string $color): null|string
    {
        $chosen = self::normalize($color);

        return $chosen === self::ROUND_FORM_DEFAULT ? null : $chosen;
    }

    /**
     * The colour of a round without its own: a distinct palette colour by its position in the schedule.
     */
    public static function automatic(int $schedulePosition): string
    {
        return self::PALETTE[$schedulePosition % count(self::PALETTE)];
    }

    /**
     * What a round saved with this colour keeps as its text colour: the automatic one for the organiser's colour,
     * null without one (the automatic background depends on the schedule).
     */
    public static function textForChosen(null|string $chosenColor): null|string
    {
        $chosen = self::chosen($chosenColor);

        return $chosen !== null ? self::text($chosen) : null;
    }

    public static function text(string $background): string
    {
        $luminance = self::apcaLuminance(self::normalize($background) ?? '#000000');

        return abs(self::apcaContrast(0.0, $luminance)) >= abs(self::apcaContrast(1.0, $luminance)) ? '#000000' : '#ffffff';
    }

    /**
     * APCA lightness contrast Lc of text on a background (SAPC/APCA 0.0.98G-4g), both given as APCA luminance:
     * positive for dark text on a light background, negative for light text on a dark one, 0 below the clip.
     */
    public static function apcaContrast(float $textLuminance, float $backgroundLuminance): float
    {
        $text = self::softClampBlack($textLuminance);
        $background = self::softClampBlack($backgroundLuminance);

        if (abs($background - $text) < 0.0005) {
            return 0.0;
        }

        if ($background > $text) {
            $contrast = ($background ** 0.56 - $text ** 0.57) * 1.14;

            return $contrast < 0.1 ? 0.0 : ($contrast - 0.027) * 100;
        }

        $contrast = ($background ** 0.65 - $text ** 0.62) * 1.14;

        return $contrast > -0.1 ? 0.0 : ($contrast + 0.027) * 100;
    }

    /**
     * APCA screen luminance of a #rrggbb colour: plain 2.4 power per channel, no linear toe.
     */
    public static function apcaLuminance(string $hex): float
    {
        $channel = static fn (string $component): float => (hexdec($component) / 255) ** 2.4;

        return 0.2126729 * $channel(substr($hex, 1, 2))
            + 0.7151522 * $channel(substr($hex, 3, 2))
            + 0.0721750 * $channel(substr($hex, 5, 2));
    }

    private static function softClampBlack(float $luminance): float
    {
        return $luminance > 0.022 ? $luminance : $luminance + (0.022 - $luminance) ** 1.414;
    }

    /**
     * Lowercase #rrggbb, or null for anything that is not a hex colour.
     */
    private static function normalize(null|string $color): null|string
    {
        if ($color === null || preg_match('/^#?([0-9a-f]{3}|[0-9a-f]{6})$/i', trim($color), $matches) !== 1) {
            return null;
        }

        $hex = strtolower($matches[1]);

        if (strlen($hex) === 3) {
            $hex = $hex[0] . $hex[0] . $hex[1] . $hex[1] . $hex[2] . $hex[2];
        }

        return '#' . $hex;
    }
}
