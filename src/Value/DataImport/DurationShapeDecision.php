<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

/**
 * What DurationColumnResolver says about one ambiguous shape of a time column: resolved (read every such value by
 * $rule), or a question for the player with the readings, how each fits, and rows to show.
 */
final readonly class DurationShapeDecision
{
    /**
     * @param list<DurationShapeOption> $options
     * @param list<int> $exampleRows Indexes into the cells handed to the resolver
     */
    public function __construct(
        public DurationShape $shape,
        /** The rule every value of the shape is read by - null means: ask the player */
        public null|DurationReadingRule $rule,
        /** When asking: the reading that fits the rows best, to offer first - never applied by itself */
        public null|DurationReadingRule $suggested,
        public array $options,
        public int $rows,
        /** Rows of the shape that have a piece count - the ones a pace could be checked on */
        public int $checkedRows,
        public array $exampleRows,
    ) {
    }

    public function isResolved(): bool
    {
        return $this->rule !== null;
    }
}
