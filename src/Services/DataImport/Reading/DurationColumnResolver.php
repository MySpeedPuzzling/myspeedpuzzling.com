<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport\Reading;

use SpeedPuzzling\Web\Value\DataImport\DurationColumnCell;
use SpeedPuzzling\Web\Value\DataImport\DurationColumnResolution;
use SpeedPuzzling\Web\Value\DataImport\DurationReadingRule;
use SpeedPuzzling\Web\Value\DataImport\DurationShape;
use SpeedPuzzling\Web\Value\DataImport\DurationShapeDecision;
use SpeedPuzzling\Web\Value\DataImport\DurationShapeOption;

/**
 * Decides, for one time column, how each ambiguous shape is read - "17:36", a time-of-day cell "1:02", a duration
 * cell of 45 hours, a bare "237" (docs/features/data-import/README.md "Durations": one question per column and shape,
 * helped by the piece count; docs/features/data-import/value-readers.md "The column decides").
 *
 * A reading that cannot read every value of the shape is out (a "8.75" in the column: nobody writes hours.minutes
 * there). Between the others the only evidence is the pace: pieces per minute of a row under a reading, inside or
 * outside what a person can do. A shape is resolved only when one reading fits nearly all rows that have a piece
 * count, no row speaks for another reading against it, and at least half of those rows tell the two apart. Anything
 * less is a question for the player - with the readings, how each fits, and the best one offered first.
 */
final readonly class DurationColumnResolver
{
    /** 1,000 pieces in 33 hours - slower than that a time is more likely misread than puzzled */
    public const float SLOWEST_PIECES_PER_MINUTE = 0.5;

    /** 500 pieces in 8 minutes 20 seconds - nobody is faster; the add form refuses from 100 */
    public const float FASTEST_PIECES_PER_MINUTE = 60.0;

    /** Fewer rows with a piece count decide nothing */
    public const int ROWS_TO_DECIDE = 3;

    /** The share of rows with a piece count a reading must fit */
    public const float NEARLY_ALL = 0.95;

    /** The share of rows with a piece count that must fit the reading and not the other one */
    public const float TELLS_APART = 0.5;

    /** Above this share of a column's times, duration cells with :00 seconds are no coincidence in a column with seconds */
    public const float MANY_ZERO_SECONDS = 0.1;

    private const int EXAMPLE_ROWS = 5;

    /**
     * @param list<DurationColumnCell> $cells Every row of the column, in order - a row's index is how it is named in
     *        the decisions
     */
    public function resolve(array $cells): DurationColumnResolution
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
                $decisions[] = $this->decide($shape, $byShape[$shape->value], $cells);
            }
        }

        return new DurationColumnResolution($decisions);
    }

    public static function isPlausiblePace(int $seconds, int $pieces): bool
    {
        if ($seconds <= 0 || $pieces <= 0) {
            return false;
        }

        $piecesPerMinute = $pieces * 60 / $seconds;

        return $piecesPerMinute >= self::SLOWEST_PIECES_PER_MINUTE && $piecesPerMinute <= self::FASTEST_PIECES_PER_MINUTE;
    }

    /**
     * @param non-empty-array<int, DurationColumnCell> $values The cells of the shape by their row
     * @param list<DurationColumnCell> $column
     */
    private function decide(DurationShape $shape, array $values, array $column): DurationShapeDecision
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

        $plausible = [];
        $options = [];

        foreach ($rules as $name) {
            $plausible[$name] = [];

            foreach ($checkedRows as $row) {
                $seconds = $readings[$name][$row];
                $plausible[$name][$row] = $seconds !== null && self::isPlausiblePace($seconds, (int) $values[$row]->pieces);
            }

            $fits = count(array_filter($plausible[$name]));

            $options[] = new DurationShapeOption(
                DurationReadingRule::from($name),
                count(array_filter($readings[$name], static fn (null|int $seconds): bool => $seconds !== null)),
                $fits,
                $checked - $fits,
                $sameAs[$name] ?? [],
            );
        }

        $winners = [];

        foreach ($rules as $name) {
            if ($this->wins($name, $rules, $readings, $plausible, $checked)) {
                $winners[] = $name;
            }
        }

        $rule = count($winners) === 1 ? DurationReadingRule::from($winners[0]) : null;

        if ($rule === null && $shape === DurationShape::ZeroSecondsDurationCell && $this->storedValueStands($values, $column, $plausible, $checked)) {
            $rule = DurationReadingRule::AsStored;
        }

        return new DurationShapeDecision(
            $shape,
            $rule,
            $rule === null ? self::bestFitting($options) : null,
            $options,
            count($values),
            $checked,
            self::exampleRows($values),
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
     * @param list<string> $rules
     * @param array<string, array<int, null|int>> $readings
     * @param array<string, array<int, bool>> $plausible
     */
    private function wins(string $name, array $rules, array $readings, array $plausible, int $checked): bool
    {
        if ($checked < self::ROWS_TO_DECIDE || in_array(null, $readings[$name], true)) {
            return false;
        }

        if (count(array_filter($plausible[$name])) / $checked < self::NEARLY_ALL) {
            return false;
        }

        foreach ($rules as $other) {
            // A reading that cannot read every value is no answer for the column ("8.75" is no "hours.minutes")
            if ($other === $name || in_array(null, $readings[$other], true)) {
                continue;
            }

            $apart = 0;

            foreach ($plausible[$name] as $row => $fits) {
                if ($fits === false && $plausible[$other][$row]) {
                    // A row that only the other reading explains
                    return false;
                }

                if ($fits && $plausible[$other][$row] === false) {
                    $apart++;
                }
            }

            if ($apart / $checked < self::TELLS_APART) {
                return false;
            }
        }

        return true;
    }

    /**
     * A duration cell under 24 hours with :00 seconds is taken as stored unless the column gives a reason to doubt it:
     * a pace that does not fit, a duration cell of 24 hours or more next to it (the same typing habit), or - in a
     * column whose other times have seconds - more of them than chance leaves.
     *
     * @param non-empty-array<int, DurationColumnCell> $values
     * @param list<DurationColumnCell> $column
     * @param array<string, array<int, bool>> $plausible
     */
    private function storedValueStands(array $values, array $column, array $plausible, int $checked): bool
    {
        $asStored = $plausible[DurationReadingRule::AsStored->value] ?? [];

        if ($checked > 0 && count(array_filter($asStored)) / $checked < self::NEARLY_ALL) {
            return false;
        }

        $times = 0;
        $withSeconds = 0;

        foreach ($column as $cell) {
            if ($cell->read->shape === DurationShape::LongDurationCell) {
                return false;
            }

            if ($cell->read->readings !== []) {
                $times++;
            }

            if ($cell->read->shape === null && $cell->read->seconds !== null && $cell->read->seconds % 60 !== 0) {
                $withSeconds++;
            }
        }

        return $withSeconds === 0 || count($values) < 2 || count($values) / max(1, $times) <= self::MANY_ZERO_SECONDS;
    }

    /**
     * The one reading that fits more rows than any other - null when none fits a row or two fit equally many.
     *
     * @param list<DurationShapeOption> $options
     */
    private static function bestFitting(array $options): null|DurationReadingRule
    {
        $best = null;
        $tie = false;

        foreach ($options as $option) {
            if ($best === null || $option->plausibleRows > $best->plausibleRows) {
                $best = $option;
                $tie = false;
            } elseif ($option->plausibleRows === $best->plausibleRows) {
                $tie = true;
            }
        }

        return $best === null || $tie || $best->plausibleRows === 0 ? null : $best->rule;
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
