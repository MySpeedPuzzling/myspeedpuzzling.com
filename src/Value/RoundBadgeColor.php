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
 * - Text: black or white, whichever contrasts more with the background (WCAG relative luminance). Never the
 *   organiser's text colour: production had white on #fe696a (2.8:1) and on #ffc107 (1.6:1).
 */
final class RoundBadgeColor
{
    public const array PALETTE = [
        '#e6194b', '#3cb44b', '#ffe119', '#0082c8', '#f58231', '#911eb4', '#46f0f0', '#d2f53c', '#fabebe', '#008080',
        '#e6beff', '#aa6e28', '#800000', '#000075', '#808000', '#000000', '#9a6324', '#469990', '#fffac8', '#dcbeff',
    ];

    private const string ROUND_FORM_DEFAULT = '#fe696a';

    public static function background(null|string $chosenColor, int $schedulePosition): string
    {
        $chosen = self::normalize($chosenColor);

        if ($chosen !== null && $chosen !== self::ROUND_FORM_DEFAULT) {
            return $chosen;
        }

        return self::PALETTE[$schedulePosition % count(self::PALETTE)];
    }

    public static function text(string $background): string
    {
        $luminance = self::luminance(self::normalize($background) ?? '#000000');

        $contrastWithBlack = ($luminance + 0.05) / 0.05;
        $contrastWithWhite = 1.05 / ($luminance + 0.05);

        return $contrastWithBlack >= $contrastWithWhite ? '#000000' : '#ffffff';
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

    private static function luminance(string $hex): float
    {
        $channel = static function (string $component): float {
            $value = hexdec($component) / 255;

            return $value <= 0.03928 ? $value / 12.92 : (($value + 0.055) / 1.055) ** 2.4;
        };

        return 0.2126 * $channel(substr($hex, 1, 2))
            + 0.7152 * $channel(substr($hex, 3, 2))
            + 0.0722 * $channel(substr($hex, 5, 2));
    }
}
