<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use SpeedPuzzling\Web\Exceptions\InvalidTableLabels;

/**
 * A hall plan's table labels (table-labels-and-plates.md "Data model"): letters × numbers × places per table, e.g.
 * SPWS = letters "A-H", numbers "1-22", leave out "H22" → A1 … G22 (175 tables); with 2 places "A1-1, A1-2, …" (350).
 * The list's order is the seating order (fastest at the first label): `letters` = A1, A2 … A22, B1 …; `numbers` = A1,
 * B1 … H1, A2 …. Places always run inside a table. No letters = plain table numbers with places ("12-1", "12-2").
 * JS twin: generate() in assets/table_labels.js (shared cases tests/fixtures/table-labels-cases.json).
 */
readonly final class TableLabelGenerator
{
    public const array SEAT_STYLES = ['dash', 'letter', 'side'];
    public const array ORDERS = ['letters', 'numbers'];
    public const int MAX_SEATS = 4;

    /**
     * @return list<string>
     * @throws InvalidTableLabels
     */
    public static function generate(string $letters, string $numbers, int $seats = 1, string $seatStyle = 'dash', string $order = 'letters', string $skip = ''): array
    {
        $letterList = self::letters($letters);
        $numberList = self::numbers($numbers);

        if ($seats < 1 || $seats > self::MAX_SEATS) {
            throw new InvalidTableLabels([['label' => (string) $seats, 'reason' => 'seats']]);
        }

        if (!in_array($seatStyle, self::SEAT_STYLES, true) || ($seatStyle === 'side' && $seats !== 2 && $seats !== 1)) {
            throw new InvalidTableLabels([['label' => $seatStyle, 'reason' => 'seat_style']]);
        }

        $skipped = [];

        foreach (preg_split('/[\s,;]+/u', trim($skip)) ?: [] as $token) {
            if ($token !== '') {
                $skipped[TableLabels::key($token)] = true;
            }
        }

        $tables = [];

        if ($letterList === []) {
            foreach ($numberList as $number) {
                $tables[] = (string) $number;
            }
        } elseif ($order === 'numbers') {
            foreach ($numberList as $number) {
                foreach ($letterList as $letter) {
                    $tables[] = $letter . $number;
                }
            }
        } else {
            foreach ($letterList as $letter) {
                foreach ($numberList as $number) {
                    $tables[] = $letter . $number;
                }
            }
        }

        $labels = [];

        foreach ($tables as $table) {
            if (isset($skipped[TableLabels::key($table)])) {
                continue;
            }

            for ($seat = 1; $seat <= $seats; $seat++) {
                $label = $seats === 1 ? $table : self::seatLabel($table, $seat, $seatStyle);

                if (!isset($skipped[TableLabels::key($label)])) {
                    $labels[] = $label;
                }
            }

            if (count($labels) > TableLabels::MAX_COUNT) {
                throw new InvalidTableLabels([['label' => '', 'reason' => 'too_many']]);
            }
        }

        return $labels;
    }

    private static function seatLabel(string $table, int $seat, string $style): string
    {
        return match ($style) {
            'letter' => $table . chr(ord('a') + $seat - 1),
            'side' => $table . ' ' . ($seat === 1 ? 'L' : 'R'),
            default => $table . '-' . $seat,
        };
    }

    /**
     * "A-H" / "A, C, E" / "A-C, F" / "" → the letters (A-Z, upper-cased, each once in the given order).
     *
     * @return list<string>
     * @throws InvalidTableLabels
     */
    private static function letters(string $text): array
    {
        $text = strtoupper(trim($text));

        if ($text === '') {
            return [];
        }

        $letters = [];

        foreach (preg_split('/\s*[,;]\s*|\s+/', (string) preg_replace('/\s*[-–—]\s*/u', '-', $text)) ?: [] as $token) {
            if ($token === '') {
                continue;
            }

            if (preg_match('/^([A-Z])(?:-([A-Z]))?$/', $token, $match) !== 1) {
                throw new InvalidTableLabels([['label' => $token, 'reason' => 'letters']]);
            }

            $from = ord($match[1]);
            $to = isset($match[2]) ? ord($match[2]) : $from;

            if ($to < $from) {
                throw new InvalidTableLabels([['label' => $token, 'reason' => 'letters']]);
            }

            for ($code = $from; $code <= $to; $code++) {
                $letters[chr($code)] = true;
            }
        }

        return array_keys($letters);
    }

    /**
     * "1-22" / "1-10, 15" → the numbers (1..9999, each once in the given order).
     *
     * @return list<int>
     * @throws InvalidTableLabels
     */
    private static function numbers(string $text): array
    {
        $text = trim($text);
        $numbers = [];

        foreach (preg_split('/\s*[,;]\s*|\s+/', (string) preg_replace('/\s*[-–—]\s*/u', '-', $text)) ?: [] as $token) {
            if ($token === '') {
                continue;
            }

            if (preg_match('/^(\d{1,4})(?:-(\d{1,4}))?$/', $token, $match) !== 1) {
                throw new InvalidTableLabels([['label' => $token, 'reason' => 'numbers']]);
            }

            $from = (int) $match[1];
            $to = isset($match[2]) ? (int) $match[2] : $from;

            if ($from < 1 || $to < $from || $to > TableLabels::MAX_COUNT) {
                throw new InvalidTableLabels([['label' => $token, 'reason' => 'numbers']]);
            }

            for ($number = $from; $number <= $to; $number++) {
                $numbers[$number] = true;
            }
        }

        if ($numbers === []) {
            throw new InvalidTableLabels([['label' => $text, 'reason' => 'numbers']]);
        }

        return array_keys($numbers);
    }
}
