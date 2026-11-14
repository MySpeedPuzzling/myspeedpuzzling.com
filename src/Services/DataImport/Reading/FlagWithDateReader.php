<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport\Reading;

use SpeedPuzzling\Web\Value\DataImport\CellInput;
use SpeedPuzzling\Web\Value\DataImport\CellInputKind;
use SpeedPuzzling\Web\Value\DataImport\DateCertainty;
use SpeedPuzzling\Web\Value\DataImport\ImportText;
use SpeedPuzzling\Web\Value\DataImport\ReadDate;
use SpeedPuzzling\Web\Value\DataImport\ReadFlagWithDate;
use SpeedPuzzling\Web\Value\DataImport\ReadYesNo;
use SpeedPuzzling\Web\Value\DataImport\YesNoCertainty;

/**
 * Reads a cell that holds two facts - "Y - 30/12/2023", "Yes (Jan 2023)", "y 2022": a yes/no and when
 * (docs/features/data-import/README.md "One cell holding two facts", docs/features/data-import/value-readers.md
 * "A flag with a date").
 *
 * The flag is read by YesNoReader, the date by ImportDateReader - with all its rules (orders, precision, a missing
 * year). A flag alone has no date; a date alone has no flag (what that means is the column's business, not this
 * reader's). Never throws.
 */
final readonly class FlagWithDateReader
{
    // What may stand between the flag and the date
    private const string BETWEEN = '[\s\-–—:,;(\[]';

    public function __construct(
        private YesNoReader $yesNoReader,
        private ImportDateReader $dateReader,
    ) {
    }

    public function read(CellInput $cell): ReadFlagWithDate
    {
        if ($cell->kind !== CellInputKind::Text) {
            return $this->typed($cell);
        }

        if (ImportText::isTooLong($cell->asText())) {
            return new ReadFlagWithDate($this->yesNoReader->read($cell), ReadDate::unreadable());
        }

        $text = ImportText::clean($cell->asText());

        if ($text === '') {
            return new ReadFlagWithDate(ReadYesNo::blank(), ReadDate::noDate());
        }

        $flag = $this->yesNoReader->read(CellInput::text($text));

        if ($flag->certainty === YesNoCertainty::Known) {
            return new ReadFlagWithDate($flag, ReadDate::noDate());
        }

        $date = $this->dateReader->read(CellInput::text($text));

        if ($date->certainty !== DateCertainty::Unreadable) {
            return new ReadFlagWithDate(ReadYesNo::blank(), $date);
        }

        $wordAndDate = null;

        // The flag first: "Y - 30/12/2023", "Yes (Jan 2023)", "y 2022"
        if (preg_match('/^(.+?)' . self::BETWEEN . '+(.+?)[)\]]?$/su', $text, $match) === 1) {
            $head = $this->yesNoReader->read(CellInput::text($match[1]));

            if ($head->certainty === YesNoCertainty::Known) {
                return $this->flagAnd($head, $match[2]);
            }

            $after = $this->dateReader->read(CellInput::text($match[2]));

            if ($after->certainty !== DateCertainty::Unreadable) {
                // "maybe 2022": a word nobody knows and a date - the word is the question
                $wordAndDate = new ReadFlagWithDate($head, $after);
            }
        }

        // The flag last: "30/12/2023 - Y"
        if (preg_match('/^(.+?)' . self::BETWEEN . '+([^\s\-–—:,;(\[]+?)[)\]]?$/su', $text, $match) === 1) {
            $tail = $this->yesNoReader->read(CellInput::text($match[2]));
            $before = $this->dateReader->read(CellInput::text($match[1]));

            if ($tail->certainty === YesNoCertainty::Known && $before->certainty !== DateCertainty::Unreadable) {
                return new ReadFlagWithDate($tail, $before);
            }
        }

        return $wordAndDate ?? new ReadFlagWithDate($flag, ReadDate::unreadable());
    }

    private function typed(CellInput $cell): ReadFlagWithDate
    {
        $flag = $this->yesNoReader->read($cell);

        if ($flag->certainty !== YesNoCertainty::Unknown) {
            return new ReadFlagWithDate($flag, ReadDate::noDate());
        }

        $date = $this->dateReader->read($cell);

        return $date->certainty === DateCertainty::Unreadable
            ? new ReadFlagWithDate($flag, $date)
            : new ReadFlagWithDate(ReadYesNo::blank(), $date);
    }

    private function flagAnd(ReadYesNo $flag, string $rest): ReadFlagWithDate
    {
        $date = $this->dateReader->read(CellInput::text($rest));

        return $date->certainty === DateCertainty::Unreadable
            ? new ReadFlagWithDate($flag, $date, $rest)
            : new ReadFlagWithDate($flag, $date);
    }
}
