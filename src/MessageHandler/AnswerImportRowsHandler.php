<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Entity\DataImportRow;
use SpeedPuzzling\Web\Entity\DataImportSheet;
use SpeedPuzzling\Web\Exceptions\DataImportNotFound;
use SpeedPuzzling\Web\Exceptions\ImportRowAnswerRefused;
use SpeedPuzzling\Web\Exceptions\ImportRowsChangedMeanwhile;
use SpeedPuzzling\Web\Message\AnswerImportRows;
use SpeedPuzzling\Web\Message\BuildImportPuzzles;
use SpeedPuzzling\Web\Message\LinkEqualImportRows;
use SpeedPuzzling\Web\Query\GetImportSheetRows;
use SpeedPuzzling\Web\Repository\DataImportRepository;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Repository\DataImportSheetRepository;
use SpeedPuzzling\Web\Services\DataImport\ImportEqualRows;
use SpeedPuzzling\Web\Services\DataImport\ImportRowAnswers;
use SpeedPuzzling\Web\Services\DataImport\ImportRowParser;
use SpeedPuzzling\Web\Services\DataImport\PendingQuestionRows;
use SpeedPuzzling\Web\Services\DataImport\SheetLookup;
use SpeedPuzzling\Web\Services\DataImport\SheetLookupFactory;
use SpeedPuzzling\Web\Services\DataImport\StoredCells;
use SpeedPuzzling\Web\Services\Doctrine\IdLock;
use SpeedPuzzling\Web\Value\DataImport\ImportField;
use SpeedPuzzling\Web\Value\DataImport\ImportKeyFold;
use SpeedPuzzling\Web\Value\DataImport\ImportMapping;
use SpeedPuzzling\Web\Value\DataImport\ImportSheetOptions;
use SpeedPuzzling\Web\Value\DataImport\ImportText;
use SpeedPuzzling\Web\Value\DataImport\ParsedImportRow;
use SpeedPuzzling\Web\Value\DataImportRowKind;
use SpeedPuzzling\Web\Value\DataImportRowState;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\DispatchAfterCurrentBusStamp;

/**
 * Under the import's lock (docs/features/data-import/pairing-screens.md "AnswerImportRows"). **Everything is checked
 * before anything is written**:
 *
 * - the import is the player's, is being set up and still has its rows' content; every row named is one of it;
 * - every typed value is read by the value readers of its field (ImportRowAnswers::read()) - one that can not be
 *   read for sure refuses the message, with its field;
 * - three-way: a row's sheet is parsed for its current mapping (a sheet that is just being read again is not
 *   answered into - the running parse read its rows before the answer), the one row of a row sheet still has the
 *   stamp its page showed, and of a group of "To fix" only the rows that still carry the group's problem are taken.
 *
 * Then each row keeps the answer and **is read again here, this row only** - the same parser, mapping, options and
 * lookup as the sheet's parse (of a puzzle list only the names these rows have), so the row is what a whole parse
 * would make of it. The sheet's counts move by what
 * the rows were and are; rows that are equal to a changed row before or after are numbered again (ImportEqualRows -
 * the same rule as the parse's). Afterwards, once this is committed: the same result on two sheets is linked again
 * and the import's puzzles are built and paired again.
 *
 * Bounded: at most ImportRowAnswers::MAX_ROWS rows a message.
 */
#[AsMessageHandler]
readonly final class AnswerImportRowsHandler
{
    /** Rows read to number equal rows again - far more than the rows of a message can be equal to */
    private const int MAX_EQUAL_ROWS = 5000;

    public function __construct(
        private DataImportRepository $dataImportRepository,
        private DataImportSheetRepository $dataImportSheetRepository,
        private DataImportRowRepository $dataImportRowRepository,
        private GetImportSheetRows $sheetRows,
        private ImportRowParser $parser,
        private ImportRowAnswers $answers,
        private SheetLookupFactory $lookupFactory,
        private MessageBusInterface $messageBus,
        private IdLock $idLock,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @return int the rows that were changed - for one answer for a group: the rows whose problem went away
     *
     * @throws DataImportNotFound
     * @throws ImportRowAnswerRefused
     * @throws ImportRowsChangedMeanwhile
     */
    public function __invoke(AnswerImportRows $message): int
    {
        if (Uuid::isValid($message->importId) === false) {
            throw new DataImportNotFound();
        }

        $this->idLock->lockUntilCommit(Uuid::fromString($message->importId));
        $import = $this->dataImportRepository->getOwnedBy($message->importId, $message->playerId);

        if ($import->isBeingSetUp() === false || $import->cellsWipedAt !== null) {
            throw new DataImportNotFound();
        }

        $typed = [];
        $takenBack = [];

        foreach ($message->answers as $name => $text) {
            $field = ImportField::tryFrom((string) $name);

            if ($field === null || ImportRowAnswers::isAnswerable($field->value) === false) {
                throw new ImportRowAnswerRefused(ImportRowAnswerRefused::NOT_ANSWERABLE, (string) $name);
            }

            if (trim($text) === '') {
                $takenBack[] = $field->value;

                continue;
            }

            $typed[$field->value] = $this->answers->read($field, $text)
                ?? throw new ImportRowAnswerRefused(ImportRowAnswerRefused::UNREADABLE, $field->value);
        }

        if ($typed === [] && $takenBack === [] && $message->leaveOut === null) {
            throw new ImportRowAnswerRefused(ImportRowAnswerRefused::NOTHING);
        }

        $rowIds = array_values(array_unique(array_map(strtolower(...), $message->rowIds)));

        if (count($rowIds) > ImportRowAnswers::MAX_ROWS) {
            throw new ImportRowAnswerRefused(ImportRowAnswerRefused::TOO_MANY_ROWS);
        }

        $rows = $this->dataImportRowRepository->ofImportByIds($import, $rowIds);

        if (count($rows) !== count($rowIds)) {
            throw new DataImportNotFound();
        }

        $changing = [];

        foreach ($rows as $row) {
            $sheet = $row->sheet;
            $current = $sheet->isParsed() && $sheet->mapping !== null && $sheet->setUpFailure === null
                && $row->canBeParsed() && $row->parsedVersion === $sheet->mappingVersion && $row->solvingTimeId === null;

            if ($message->onlyWithProblem !== null) {
                // One answer for a group: a row that was answered, read anew or left meanwhile is passed over
                $problem = $current ? self::problem($row, $message->onlyWithProblem) : null;

                if ($problem === null) {
                    continue;
                }

                // A shared value only where the rows share what the sheet says, and only for that very field: rows
                // without a name (or with nothing in the cell) are not one puzzle - each is answered on its own
                if ($typed !== [] && ($problem['value'] === '' || array_keys($typed) !== [$problem['field']] || $takenBack !== [])) {
                    throw new ImportRowAnswerRefused(ImportRowAnswerRefused::NOT_ANSWERABLE, $problem['field']);
                }

                $changing[] = $row;

                continue;
            }

            if ($current === false || ($message->seenStamp !== null && $row->stamp() !== $message->seenStamp)) {
                throw new ImportRowsChangedMeanwhile();
            }

            $changing[] = $row;
        }

        // Checked - from here on nothing refuses
        $now = $this->clock->now();
        /** @var array<string, array{sheet: DataImportSheet, moved: array<string, int>, keys: array<string, true>, rows: array<string, DataImportRow>, answers: array<string, array<string, mixed>>, was: array<string, string>}> $bySheet */
        $bySheet = [];

        foreach ($changing as $row) {
            $sheetId = $row->sheet->id->toString();
            $rowId = $row->id->toString();
            $bySheet[$sheetId] ??= ['sheet' => $row->sheet, 'moved' => [], 'keys' => [], 'rows' => [], 'answers' => [], 'was' => []];
            $bySheet[$sheetId]['was'][$rowId] = self::countedAs($row);

            if ($row->equalKey !== null) {
                $bySheet[$sheetId]['keys'][$row->equalKey] = true;
            }

            $answers = $row->answers ?? [];

            foreach ($typed as $field => $answer) {
                $answers[$field] = $answer;
            }

            foreach ($takenBack as $field) {
                unset($answers[$field]);
            }

            if ($message->leaveOut === true) {
                $answers[ImportRowAnswers::LEFT_OUT] = true;
            } elseif ($message->leaveOut === false) {
                unset($answers[ImportRowAnswers::LEFT_OUT]);
            }

            /** @var array<string, mixed> $answers */
            $row->answered($answers);
            $bySheet[$sheetId]['rows'][$rowId] = $row;
            $bySheet[$sheetId]['answers'][$rowId] = $answers;
        }

        $fixed = 0;

        foreach ($bySheet as $sheetId => $changed) {
            $sheet = $changed['sheet'];
            $mapping = ImportMapping::fromArray($sheet->mapping ?? []);
            $options = ImportSheetOptions::fromArray($sheet->options);
            $valueAnswers = $sheet->valueAnswers ?? [];
            $read = function (DataImportRow $row, null|SheetLookup $lookup) use ($mapping, $options, $valueAnswers, $changed): ParsedImportRow {
                /** @var array<int, array<string, mixed>> $cells */
                $cells = $row->cells ?? [];
                $answers = $changed['answers'][$row->id->toString()];

                return ImportRowAnswers::settled(
                    PendingQuestionRows::mark($this->parser->parse($row->rowNumber, $cells, $mapping, $options, $valueAnswers, $lookup, $answers), $cells, $mapping),
                    $answers,
                );
            };

            // Brand and pieces from the workbook's puzzle list: only the names these rows have are looked up there -
            // never the whole list in a request (it may hold 50,000 rows)
            $lookup = null;

            if ($options->lookupSheetId !== null && $mapping->isImported()) {
                $names = [];

                foreach ($changed['rows'] as $row) {
                    $name = $read($row, null)->parsed['name'] ?? null;

                    if (is_string($name)) {
                        $names[] = $name;
                    }
                }

                $lookup = $this->lookupOf($import, $options->lookupSheetId, $names);
            }

            foreach ($changed['rows'] as $rowId => $row) {
                $row->parsedAs($sheet->mappingVersion, $read($row, $lookup)->toRecord());

                if ($row->equalKey !== null) {
                    $bySheet[$sheetId]['keys'][$row->equalKey] = true;
                }

                $was = $changed['was'][$rowId];
                $is = self::countedAs($row);
                $bySheet[$sheetId]['moved'][$was] = ($bySheet[$sheetId]['moved'][$was] ?? 0) - 1;
                $bySheet[$sheetId]['moved'][$is] = ($bySheet[$sheetId]['moved'][$is] ?? 0) + 1;

                // "Rows fixed: N" counts what was fixed: the group's problem is gone from the row
                if ($message->onlyWithProblem === null || $message->leaveOut === true || self::problem($row, $message->onlyWithProblem) === null) {
                    $fixed++;
                }
            }
        }

        foreach ($bySheet as $changed) {
            $moved = $changed['moved'];
            $changed['sheet']->rowsReadAgain($moved['understood'] ?? 0, $moved['needLook'] ?? 0, $moved['leftOut'] ?? 0, $moved['waiting'] ?? 0);
            $this->numberEqualRows($changed['sheet'], array_map(strval(...), array_keys($changed['keys'])), $changed['rows']);
        }

        if ($changing !== []) {
            $import->pairingDue($now);

            // After this is committed: both read the rows as the database has them
            $this->messageBus->dispatch(new LinkEqualImportRows($message->importId), [new DispatchAfterCurrentBusStamp()]);
            $this->messageBus->dispatch(new BuildImportPuzzles($message->importId), [new DispatchAfterCurrentBusStamp()]);
        }

        return $fixed;
    }

    /**
     * The puzzle list's word on these names only - what SheetLookupFactory would build from the whole list, for the
     * names asked: the list's name cells are read alone (one light statement), folded like a key's, and only the
     * rows that hold one of the names are read whole. A name the list has twice differently is still seen as such.
     *
     * @param list<string> $names
     */
    private function lookupOf(DataImport $import, string $listSheetId, array $names): null|SheetLookup
    {
        $list = null;

        foreach ($this->dataImportSheetRepository->ofImport($import) as $sheet) {
            if ($sheet->id->toString() === $listSheetId && $sheet->mapping !== null) {
                $list = $sheet;
            }
        }

        if ($list === null || $list->mapping === null) {
            return null;
        }

        $listMapping = ImportMapping::fromArray($list->mapping);
        $nameColumn = $listMapping->column(ImportField::PuzzleName);
        $wanted = [];

        foreach ($names as $name) {
            $wanted[ImportKeyFold::v1($name)] = true;
        }

        unset($wanted['']);

        if ($nameColumn === null || $wanted === []) {
            return new SheetLookup();
        }

        $rowNumbers = [];

        foreach ($this->sheetRows->cellsOfColumn($listSheetId, $nameColumn->column) as $rowNumber => $cell) {
            if (isset($wanted[ImportKeyFold::v1(ImportText::clean(StoredCells::shown($cell)))])) {
                $rowNumbers[] = $rowNumber;
            }
        }

        return $this->lookupFactory->build($listMapping, $this->sheetRows->rowsByNumbers($listSheetId, array_slice($rowNumbers, 0, self::MAX_EQUAL_ROWS)));
    }

    /**
     * The rows of the sheet that are equal by a key a changed row had or has now are numbered and linked again -
     * what ParseImportSheetHandler does for the whole sheet, here for those groups only. The rows this message
     * changed are grouped by the key they have now; the others are as the database has them.
     *
     * @param list<string> $keys
     * @param array<string, DataImportRow> $changed by id
     */
    private function numberEqualRows(DataImportSheet $sheet, array $keys, array $changed): void
    {
        if ($keys === []) {
            return;
        }

        $groups = [];

        foreach ([...$this->dataImportRowRepository->ofSheetByEqualKeys($sheet, $keys, self::MAX_EQUAL_ROWS), ...array_values($changed)] as $row) {
            if ($row->equalKey !== null && $row->canBeParsed() && in_array($row->equalKey, $keys, true)) {
                $groups[$row->equalKey][$row->id->toString()] = $row;
            }
        }

        foreach ($groups as $group) {
            $facts = [];

            foreach ($group as $id => $row) {
                $attempt = $row->parsed['attempt'] ?? null;
                $content = $row->parsed['content'] ?? '';
                $facts[] = ['id' => (string) $id, 'attempt' => is_int($attempt) ? $attempt : null, 'content' => is_string($content) ? $content : '', 'rowNumber' => $row->rowNumber];
            }

            foreach (ImportEqualRows::number($facts) as $id => $numbered) {
                $row = $group[$id];
                $isResult = $row->kind === DataImportRowKind::Result || $row->kind === DataImportRowKind::Both;
                $row->numberedAmongEqualRows(
                    $numbered['occurrence'],
                    // Only a result's key holds the occurrence: two rows for one owned puzzle are one puzzle
                    $isResult && $row->puzzleKey !== null ? ImportEqualRows::resultKey($row->parsed ?? [], $row->puzzleKey, $numbered['occurrence']) : null,
                    $numbered['sameAs'] === null ? null : $group[$numbered['sameAs']],
                );
            }
        }
    }

    /**
     * Which of its sheet's counts a row is in (RecordImportSheetParsed's four).
     */
    private static function countedAs(DataImportRow $row): string
    {
        if ($row->state === DataImportRowState::LeftOut) {
            return 'leftOut';
        }

        $problems = $row->problems ?? [];

        foreach ($problems as $problem) {
            if (is_array($problem) && ($problem['waiting'] ?? false) === true) {
                return 'waiting';
            }
        }

        return $problems === [] ? 'understood' : 'needLook';
    }

    /**
     * The problem of the row a group of "To fix" is named by - null: the row does not have it (any more).
     *
     * @return null|array{field: string, value: string}
     */
    private static function problem(DataImportRow $row, string $key): null|array
    {
        foreach ($row->problems ?? [] as $problem) {
            if (is_array($problem) === false || is_string($problem['code'] ?? null) === false) {
                continue;
            }

            $field = is_string($problem['field'] ?? null) ? $problem['field'] : null;
            $value = is_string($problem['value'] ?? null) ? $problem['value'] : null;

            if (ImportRowAnswers::problemKey($field, $problem['code'], $value) === $key) {
                return ['field' => $field ?? '', 'value' => $value ?? ''];
            }
        }

        return null;
    }
}
