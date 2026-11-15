<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use SpeedPuzzling\Web\Value\DataImport\ImportField;
use SpeedPuzzling\Web\Value\DataImport\ImportMapping;
use SpeedPuzzling\Web\Value\DataImport\ImportQuestionType;
use SpeedPuzzling\Web\Value\DataImport\ParsedImportRow;

/**
 * A question never holds back its whole sheet (docs/features/data-import/columns-screen.md "A question and its
 * rows"): the sheet is parsed as soon as it has a mapping, and only **the rows a pending question decides** wait -
 * marked here, right after the parser read them. Such a row is neither ready nor "needs a look": its problem carries
 * `waiting: true`, and the sheet counts it in `rows_waiting`. Answering the question parses the sheet again.
 *
 * Which rows a question decides is what the parser left open for want of the answer: a time of a shape nobody
 * settled, a short date, a date whose order or year is open. "Were the typed dates turned around?" leaves nothing
 * open in the parser (it reads them as they are) - there every row with a typed date waits.
 */
final class PendingQuestionRows
{
    /** The problem of a typed date that waits for "were they turned around?" */
    public const string TYPED_DATE_OPEN = 'date_typed_open';

    private const array DECIDED_BY = [
        'time_two_readings' => ImportQuestionType::DurationShape,
        'date_day_or_month_open' => ImportQuestionType::ShortDates,
        'date_order_open' => ImportQuestionType::DateOrder,
        'date_no_year' => ImportQuestionType::Year,
    ];

    /**
     * @param array<int, array<string, mixed>> $cells the row's cells by column
     */
    public static function mark(ParsedImportRow $row, array $cells, ImportMapping $mapping): ParsedImportRow
    {
        if ($mapping->questions === [] || $row->isData() === false) {
            return $row;
        }

        $asked = [];

        foreach ($mapping->questions as $question) {
            $asked[$question->type->value] = $question->column;
        }

        $problems = [];
        $waits = false;

        foreach ($row->problems as $problem) {
            $decidedBy = self::DECIDED_BY[$problem['code']] ?? null;

            if ($decidedBy !== null && isset($asked[$decidedBy->value])) {
                $problem['waiting'] = true;
                $waits = true;
            }

            $problems[] = $problem;
        }

        $typedDates = $asked[ImportQuestionType::TypedDates->value] ?? null;

        if ($typedDates !== null && StoredCells::kind($cells[$typedDates] ?? null) === 'date') {
            $problems[] = ['field' => ImportField::SolvedOn->value, 'code' => self::TYPED_DATE_OPEN, 'value' => mb_substr(StoredCells::shown($cells[$typedDates]), 0, 100), 'waiting' => true];
            $waits = true;
        }

        return $waits ? $row->withProblems($problems) : $row;
    }
}
