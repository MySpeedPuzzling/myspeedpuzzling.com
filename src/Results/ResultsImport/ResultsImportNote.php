<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\ResultsImport;

use SpeedPuzzling\Web\Value\EventResultsImport\EventTabProblem;

/**
 * Something to tell the organizer about one event of a check, in plain words: a problem that keeps it out, a row that
 * is skipped, or a soft warning that never blocks. Rendered by results_import/_note.html.twig from
 * `results_import.note.<code>`.
 */
readonly final class ResultsImportNote
{
    // Soft warnings of the page (never block) - the other codes are EventTabProblemCode values
    public const string PUZZLE_NOT_APPROVED = 'puzzle_not_approved';
    public const string ROWS_OUT_OF_TIME_ORDER = 'rows_out_of_time_order';
    public const string OVER_TIME_LIMIT = 'over_time_limit';

    public function __construct(
        public string $code,
        public null|string $detail = null,
        // The sheet's row (the header is 1)
        public null|int $line = null,
    ) {
    }

    public static function fromProblem(EventTabProblem $problem): self
    {
        return new self($problem->code->value, $problem->detail, $problem->line);
    }
}
