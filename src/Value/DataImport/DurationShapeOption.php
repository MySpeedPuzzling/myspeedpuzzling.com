<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * One way to read every value of an ambiguous shape in a column, with how well it fits the rows.
 */
final readonly class DurationShapeOption
{
    /**
     * @param list<DurationReadingRule> $sameAs Other rules that read every value of the column exactly like this one
     */
    public function __construct(
        public DurationReadingRule $rule,
        /** Values of the shape this rule can read */
        public int $readableRows,
        /** Rows with a piece count whose pace under this rule is one a person can puzzle at */
        public int $plausibleRows,
        /** Rows with a piece count whose pace under this rule is not (or which the rule cannot read) */
        public int $implausibleRows,
        public array $sameAs = [],
    ) {
    }
}
