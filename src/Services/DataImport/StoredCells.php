<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\DataImport\CellInput;
use SpeedPuzzling\Web\Value\DataImport\ImportText;
use SpeedPuzzling\Web\Value\DatePrecision;

/**
 * Reads a cell as `data_import_row.cells` keeps it (ImportRowCells: `k` kind, `v` value, `s` shown text, `f` formula,
 * `p` `y` `t` of a date, `z` zero padding) - and hands it to the value readers as the CellInput they take.
 */
final class StoredCells
{
    /**
     * @param null|array<mixed> $cell
     */
    public static function input(null|array $cell): CellInput
    {
        if ($cell === null) {
            return CellInput::blank();
        }

        $value = $cell['v'] ?? null;

        return match ($cell['k'] ?? null) {
            'text' => is_string($value) ? CellInput::text($value) : CellInput::blank(),
            'number' => match (true) {
                // A number its format pads with zeros is a code: its digits as shown ("0012345678905")
                isset($cell['z']) && is_string($cell['s'] ?? null) => CellInput::text($cell['s']),
                is_int($value) || is_float($value) => CellInput::number($value),
                default => CellInput::blank(),
            },
            'duration' => is_int($value) ? CellInput::duration($value) : CellInput::blank(),
            'time_of_day' => is_int($value) ? CellInput::timeOfDay($value, self::showsAClock($cell)) : CellInput::blank(),
            'date' => self::date($cell),
            'boolean' => is_bool($value) ? CellInput::boolean($value) : CellInput::blank(),
            // An error (#DIV/0!, #N/A) is no value
            default => CellInput::blank(),
        };
    }

    /**
     * The text the player saw - '' for no cell and for an error.
     *
     * @param null|array<mixed> $cell
     */
    public static function shown(null|array $cell): string
    {
        if ($cell === null || ($cell['k'] ?? null) === 'error') {
            return '';
        }

        $shown = $cell['s'] ?? $cell['v'] ?? '';

        return match (true) {
            is_string($shown) => $shown,
            is_int($shown) || is_float($shown) => ImportText::ofNumber($shown),
            is_bool($shown) => $shown ? 'TRUE' : 'FALSE',
            default => '',
        };
    }

    /**
     * @param null|array<mixed> $cell
     */
    public static function isBlank(null|array $cell): bool
    {
        return trim(self::shown($cell)) === '';
    }

    /**
     * @param null|array<mixed> $cell
     */
    public static function isFormula(null|array $cell): bool
    {
        return ($cell['f'] ?? false) === true;
    }

    /**
     * @param null|array<mixed> $cell
     */
    public static function kind(null|array $cell): null|string
    {
        $kind = $cell['k'] ?? null;

        return is_string($kind) ? $kind : null;
    }

    /**
     * What a cell holds when nobody filled it in: nothing, an unticked checkbox, a zero or an empty text a formula
     * gave, an error.
     *
     * @param null|array<mixed> $cell
     */
    public static function isDefault(null|array $cell): bool
    {
        if (self::isBlank($cell)) {
            return true;
        }

        $value = $cell['v'] ?? null;

        return $value === false
            || (self::isFormula($cell) && ($value === 0 || $value === 0.0 || $value === ''));
    }

    /**
     * The year a date cell stores - also when its format hides it.
     *
     * @param null|array<mixed> $cell
     */
    public static function storedYear(null|array $cell): null|int
    {
        $value = $cell['v'] ?? null;

        return ($cell['k'] ?? null) === 'date' && is_string($value) && preg_match('/^(\d{4})-/', $value, $match) === 1
            ? (int) $match[1]
            : null;
    }

    /**
     * @param array<mixed> $cell
     */
    private static function date(array $cell): CellInput
    {
        $value = $cell['v'] ?? null;
        $date = is_string($value) ? DateTimeImmutable::createFromFormat('!Y-m-d', $value, new \DateTimeZone('UTC')) : false;

        if ($date === false) {
            return CellInput::blank();
        }

        $precision = is_string($cell['p'] ?? null) ? DatePrecision::tryFrom($cell['p']) : null;

        return CellInput::date($date, $precision ?? DatePrecision::Day, ($cell['y'] ?? true) !== false);
    }

    /**
     * A time-only cell shown with AM / PM is a clock's - a moment, never how long something took.
     *
     * @param array<mixed> $cell
     */
    private static function showsAClock(array $cell): bool
    {
        return is_string($cell['s'] ?? null) && preg_match('/\b[ap]\.?\s?m\b/i', $cell['s']) === 1;
    }
}
