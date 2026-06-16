<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A single subject's cell within a puzzle row. A null entry means the subject
 * has not solved this puzzle in the current mode (shown as "—").
 */
readonly final class ComparisonCell
{
    public function __construct(
        public ComparisonSubjectView $subject,
        public null|ComparisonEntry $entry,
    ) {
    }
}
