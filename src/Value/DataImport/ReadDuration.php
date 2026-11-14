<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * What DurationReader made of one cell of a time column (docs/features/data-import/value-readers.md "Durations").
 *
 * $seconds is the time the import takes when nobody says otherwise - null while it has none to take (no time,
 * unreadable, or a shape with several meanings that the column has not settled). $readings holds every possible time.
 * $shape names the ambiguous shape the value has, so one answer covers the column; a value can need both that answer
 * and the player's yes for its leftover text ("17:36 for both").
 */
final readonly class ReadDuration
{
    /** @var list<int> Every possible reading in seconds, each once, in the order of the rules */
    public array $readings;

    /**
     * @param list<DurationReading> $alternatives The readings of an ambiguous shape by their rule - empty for a value
     *        with one meaning
     */
    private function __construct(
        public DurationCertainty $certainty,
        public null|int $seconds,
        public array $alternatives = [],
        public null|DurationShape $shape = null,
        /** The text around the time, trimmed - what would go to the private notes */
        public null|string $leftover = null,
    ) {
        $readings = $seconds === null ? [] : [$seconds];

        foreach ($alternatives as $alternative) {
            if (in_array($alternative->seconds, $readings, true) === false) {
                $readings[] = $alternative->seconds;
            }
        }

        $this->readings = $readings;
    }

    public static function noTime(): self
    {
        return new self(DurationCertainty::NoTime, null);
    }

    public static function unreadable(): self
    {
        return new self(DurationCertainty::Unreadable, null);
    }

    public static function certain(int $seconds): self
    {
        return new self(DurationCertainty::Certain, $seconds);
    }

    public static function fromText(int $seconds): self
    {
        return new self(DurationCertainty::ReadFromText, $seconds);
    }

    public static function withLeftover(int $seconds, string $leftover): self
    {
        return new self(DurationCertainty::ReadWithLeftovers, $seconds, leftover: $leftover);
    }

    /**
     * A shape with several meanings: nothing is taken until the column is settled.
     *
     * @param list<DurationReading> $alternatives
     */
    public static function ambiguous(DurationShape $shape, array $alternatives, null|string $leftover = null): self
    {
        if ($alternatives === []) {
            return self::unreadable();
        }

        return new self(
            $leftover === null ? DurationCertainty::TwoReadings : DurationCertainty::ReadWithLeftovers,
            null,
            $alternatives,
            $shape,
            $leftover,
        );
    }

    /**
     * A cell taken as stored, whose shape the column may still question (a duration cell with :00 seconds).
     *
     * @param list<DurationReading> $alternatives
     */
    public static function certainUnlessTheColumnSaysOtherwise(int $seconds, DurationShape $shape, array $alternatives): self
    {
        return new self(DurationCertainty::Certain, $seconds, $alternatives, $shape);
    }

    public function secondsBy(DurationReadingRule $rule): null|int
    {
        foreach ($this->alternatives as $alternative) {
            if ($alternative->rule === $rule) {
                return $alternative->seconds;
            }
        }

        return null;
    }

    /**
     * This value once its shape is settled for the column: the time by that rule - still waiting for the player's yes
     * when it has leftover text, unreadable when the rule cannot read it. A value without such a shape stays as it is.
     */
    public function settledBy(DurationReadingRule $rule): self
    {
        if ($this->shape === null) {
            return $this;
        }

        $seconds = $this->secondsBy($rule);

        if ($seconds === null) {
            return self::unreadable();
        }

        return $this->leftover === null ? self::certain($seconds) : self::withLeftover($seconds, $this->leftover);
    }
}
