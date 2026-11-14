<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport\Reading;

use SpeedPuzzling\Web\Value\DataImport\DurationColumnCell;
use SpeedPuzzling\Web\Value\DataImport\DurationColumnResolution;
use SpeedPuzzling\Web\Value\DataImport\DurationReadingRule;
use SpeedPuzzling\Web\Value\DataImport\DurationShape;
use SpeedPuzzling\Web\Value\DataImport\DurationShapeDecision;
use SpeedPuzzling\Web\Value\DataImport\DurationShapeOption;
use SpeedPuzzling\Web\Value\DataImport\DurationUnit;

/**
 * Decides, for one time column, how each ambiguous shape is read - "17:36", a time cell "1:02", a duration of 45
 * hours, a bare "237" (docs/features/data-import/README.md "Durations": one question per column and shape, helped by
 * the piece count; docs/features/data-import/value-readers.md "The column decides").
 *
 * A wrong answer here puts a result on a public leaderboard sixty times too fast, so the rows decide only when
 * nothing else can be meant: the winning reading gives an ORDINARY pace for every row that has a piece count, and
 * every other reading is far outside what anybody puzzles at for nearly all of them. The two readings of "40:00" are
 * sixty times apart - 1,000 pieces in 40 minutes or in 40 hours - and a pace that is merely possible proves nothing:
 * a slow puzzler's hours must never become a fast puzzler's minutes. Anything less is a question for the player, with
 * the readings, how each fits, and the ordinary one offered first.
 *
 * A unit in the column's header ("Time (min)") is the answer for its bare numbers; the pace never overrides it.
 */
final readonly class DurationColumnResolver
{
    /** A pace a person can puzzle at: from 1,000 pieces in 83 hours … */
    public const float SLOWEST_PIECES_PER_MINUTE = 0.2;

    /** … to 500 pieces in 8 minutes 20 seconds; the add form refuses from 100 */
    public const float FASTEST_PIECES_PER_MINUTE = 60.0;

    /** An ordinary pace is never faster than 1,000 pieces in 67 minutes - a faster reading is never chosen for the player */
    public const float ORDINARY_FASTEST_PIECES_PER_MINUTE = 15.0;

    /** The reading that loses must be far outside what anybody does: slower than 1,000 pieces in two weeks … */
    public const float FAR_TOO_SLOW_PIECES_PER_MINUTE = 0.05;

    /** … or faster than the add form accepts */
    public const float FAR_TOO_FAST_PIECES_PER_MINUTE = 100.0;

    /** Fewer rows with a piece count decide nothing … */
    public const int ROWS_TO_DECIDE = 3;

    /** … and neither does a smaller share of the shape's rows */
    public const float SHARE_OF_ROWS_TO_DECIDE = 0.3;

    /** The share of rows with a piece count for which every other reading must be far outside */
    public const float NEARLY_ALL = 0.95;

    /** Up to this share of a column's times, values with :00 seconds are chance in a column whose other times have seconds */
    public const float MANY_ZERO_SECONDS = 0.1;

    private const int EXAMPLE_ROWS = 5;

    /**
     * @param list<DurationColumnCell> $cells Every row of the column, in order - a row's index is how it is named in
     *        the decisions
     * @param null|DurationUnit $unitHint The unit the column's header names (FieldGuess::$unitHint)
     */
    public function resolve(array $cells, null|DurationUnit $unitHint = null): DurationColumnResolution
    {
        $byShape = [];

        foreach ($cells as $row => $cell) {
            if ($cell->read->shape !== null) {
                $byShape[$cell->read->shape->value][$row] = $cell;
            }
        }

        $decisions = [];

        foreach (DurationShape::cases() as $shape) {
            if (isset($byShape[$shape->value])) {
                $decisions[] = $this->decide($shape, $byShape[$shape->value], $cells, $unitHint);
            }
        }

        return new DurationColumnResolution($decisions);
    }

    public static function isPlausiblePace(int $seconds, int $pieces): bool
    {
        $pace = self::pace($seconds, $pieces);

        return $pace !== null && $pace >= self::SLOWEST_PIECES_PER_MINUTE && $pace <= self::FASTEST_PIECES_PER_MINUTE;
    }

    public static function isOrdinaryPace(int $seconds, int $pieces): bool
    {
        $pace = self::pace($seconds, $pieces);

        return $pace !== null && $pace >= self::SLOWEST_PIECES_PER_MINUTE && $pace <= self::ORDINARY_FASTEST_PIECES_PER_MINUTE;
    }

    public static function isFarOutsidePace(int $seconds, int $pieces): bool
    {
        $pace = self::pace($seconds, $pieces);

        return $pace === null || $pace < self::FAR_TOO_SLOW_PIECES_PER_MINUTE || $pace > self::FAR_TOO_FAST_PIECES_PER_MINUTE;
    }

    private static function pace(int $seconds, int $pieces): null|float
    {
        return $seconds <= 0 || $pieces <= 0 ? null : $pieces * 60 / $seconds;
    }

    /**
     * @param non-empty-array<int, DurationColumnCell> $values The cells of the shape by their row
     * @param list<DurationColumnCell> $column
     */
    private function decide(DurationShape $shape, array $values, array $column, null|DurationUnit $unitHint): DurationShapeDecision
    {
        $readings = self::readingsByRule($values);

        // Rules that read every value the same are one reading (a whole number of hours is the same "hours.minutes")
        $rules = [];
        $sameAs = [];

        foreach (array_keys($readings) as $name) {
            foreach ($rules as $kept) {
                if ($readings[$kept] === $readings[$name]) {
                    $sameAs[$kept][] = DurationReadingRule::from($name);
                    continue 2;
                }
            }

            $rules[] = $name;
        }

        $checkedRows = array_keys(array_filter(
            $values,
            static fn (DurationColumnCell $cell): bool => $cell->pieces !== null && $cell->pieces > 0,
        ));
        $checked = count($checkedRows);

        // Per rule and row with a piece count: is the pace ordinary, plausible, far outside
        $paces = [];
        $options = [];

        foreach ($rules as $name) {
            $paces[$name] = ['ordinary' => [], 'plausible' => [], 'far' => []];

            foreach ($checkedRows as $row) {
                $seconds = $readings[$name][$row] ?? 0;
                $pieces = (int) $values[$row]->pieces;

                $paces[$name]['ordinary'][$row] = self::isOrdinaryPace($seconds, $pieces);
                $paces[$name]['plausible'][$row] = self::isPlausiblePace($seconds, $pieces);
                $paces[$name]['far'][$row] = self::isFarOutsidePace($seconds, $pieces);
            }

            $plausible = count(array_filter($paces[$name]['plausible']));

            $options[] = new DurationShapeOption(
                DurationReadingRule::from($name),
                count(array_filter($readings[$name], static fn (null|int $seconds): bool => $seconds !== null)),
                count(array_filter($paces[$name]['ordinary'])),
                $plausible,
                $checked - $plausible,
                $sameAs[$name] ?? [],
            );
        }

        $rule = null;
        $byHeaderUnit = false;

        if ($shape === DurationShape::BareNumber && $unitHint !== null) {
            // The header said it - the rows are not asked
            $rule = $unitHint->rule();
            $byHeaderUnit = true;
        } else {
            $winners = array_values(array_filter(
                $rules,
                fn (string $name): bool => $this->wins($name, $rules, $readings, $paces, $checked, count($values)),
            ));

            if (count($winners) === 1) {
                $rule = DurationReadingRule::from($winners[0]);
            } elseif ($this->storedValueStands($shape, $rules, $values, $column, $readings, $paces, $checked)) {
                $rule = DurationReadingRule::AsStored;
            }
        }

        return new DurationShapeDecision(
            $shape,
            $rule,
            $rule === null ? self::mostOrdinary($options) : null,
            $options,
            count($values),
            $checked,
            self::exampleRows($values),
            $byHeaderUnit,
        );
    }

    /**
     * What each rule makes of each value - only the rules that read at least one of them.
     *
     * @param non-empty-array<int, DurationColumnCell> $values
     * @return array<string, array<int, null|int>> Seconds by rule (DurationReadingRule value) and row
     */
    private static function readingsByRule(array $values): array
    {
        $readings = [];

        foreach (DurationReadingRule::cases() as $rule) {
            $ofRule = array_map(static fn (DurationColumnCell $cell): null|int => $cell->read->secondsBy($rule), $values);

            if (array_filter($ofRule, static fn (null|int $seconds): bool => $seconds !== null) !== []) {
                $readings[$rule->value] = $ofRule;
            }
        }

        return $readings;
    }

    /**
     * The rows settle a shape for a reading only when it reads every value, enough rows have a piece count, its pace
     * is ordinary for every one of them, and every other reading that could be meant for the column is far outside
     * for nearly all of them.
     *
     * @param list<string> $rules
     * @param array<string, array<int, null|int>> $readings
     * @param array<string, array{ordinary: array<int, bool>, plausible: array<int, bool>, far: array<int, bool>}> $paces
     */
    private function wins(string $name, array $rules, array $readings, array $paces, int $checked, int $rows): bool
    {
        if (in_array(null, $readings[$name], true)) {
            return false;
        }

        if ($checked < self::ROWS_TO_DECIDE || $checked / $rows < self::SHARE_OF_ROWS_TO_DECIDE) {
            return false;
        }

        if (in_array(false, $paces[$name]['ordinary'], true)) {
            return false;
        }

        foreach ($rules as $other) {
            // A reading that cannot read every value is no answer for the column ("8.75" is no "hours.minutes")
            if ($other === $name || in_array(null, $readings[$other], true)) {
                continue;
            }

            if (count(array_filter($paces[$other]['far'])) / $checked < self::NEARLY_ALL) {
                return false;
            }
        }

        return true;
    }

    /**
     * When the rows do not settle it, a value stands as the spreadsheet stored it in two cases only:
     *
     * - it has no other reading at all (a duration of a day and more WITH seconds) and no row's pace speaks against it;
     * - it is a time without seconds (a cell, or text like "1:30:00") in a column of real h:mm:ss times - the column's
     *   other times have seconds, such values are one or at most MANY_ZERO_SECONDS of them (a time ends in :00 once
     *   in sixty), no row's pace speaks against it, and no duration of a day and more without seconds stands in the
     *   column (the mark of "45:30" typed for minutes and seconds).
     *
     * A column whose times all end in :00 is never silently hours and minutes - it is asked.
     *
     * @param list<string> $rules
     * @param non-empty-array<int, DurationColumnCell> $values
     * @param list<DurationColumnCell> $column
     * @param array<string, array<int, null|int>> $readings
     * @param array<string, array{ordinary: array<int, bool>, plausible: array<int, bool>, far: array<int, bool>}> $paces
     */
    private function storedValueStands(DurationShape $shape, array $rules, array $values, array $column, array $readings, array $paces, int $checked): bool
    {
        $asStored = DurationReadingRule::AsStored->value;

        if (isset($readings[$asStored]) === false || in_array(null, $readings[$asStored], true)) {
            return false;
        }

        if ($checked > 0 && count(array_filter($paces[$asStored]['plausible'])) / $checked < self::NEARLY_ALL) {
            return false;
        }

        if ($rules === [$asStored]) {
            return true;
        }

        if ($shape !== DurationShape::ZeroSecondsDurationCell && $shape !== DurationShape::TimeOfDayCell) {
            return false;
        }

        $times = 0;
        $withSeconds = 0;

        foreach ($column as $cell) {
            $stored = $cell->read->seconds ?? $cell->read->secondsBy(DurationReadingRule::AsStored);

            if ($cell->read->shape === DurationShape::LongDurationCell && $cell->read->secondsBy(DurationReadingRule::StoredHoursAsMinutes) !== null) {
                return false;
            }

            if ($cell->read->readings !== []) {
                $times++;
            }

            if ($stored !== null && $stored % 60 !== 0 && ($cell->read->shape === null || $cell->read->shape === DurationShape::LongDurationCell)) {
                $withSeconds++;
            }
        }

        return $withSeconds > 0 && (count($values) === 1 || count($values) / max(1, $times) <= self::MANY_ZERO_SECONDS);
    }

    /**
     * The one reading whose pace is ordinary for more rows than any other's - null when none is for any row, or two
     * are for equally many.
     *
     * @param list<DurationShapeOption> $options
     */
    private static function mostOrdinary(array $options): null|DurationReadingRule
    {
        $best = null;
        $tie = false;

        foreach ($options as $option) {
            if ($best === null || $option->ordinaryRows > $best->ordinaryRows) {
                $best = $option;
                $tie = false;
            } elseif ($option->ordinaryRows === $best->ordinaryRows) {
                $tie = true;
            }
        }

        return $best === null || $tie || $best->ordinaryRows === 0 ? null : $best->rule;
    }

    /**
     * Rows to show with the question: those with a piece count first.
     *
     * @param non-empty-array<int, DurationColumnCell> $values
     * @return list<int>
     */
    private static function exampleRows(array $values): array
    {
        $withPieces = array_keys(array_filter($values, static fn (DurationColumnCell $cell): bool => $cell->pieces !== null && $cell->pieces > 0));
        $without = array_values(array_diff(array_keys($values), $withPieces));

        return array_slice([...$withPieces, ...$without], 0, self::EXAMPLE_ROWS);
    }
}
