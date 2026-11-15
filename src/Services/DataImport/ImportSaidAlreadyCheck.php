<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\DataImport;

use SpeedPuzzling\Web\Entity\DataImport;
use SpeedPuzzling\Web\Repository\DataImportRowRepository;
use SpeedPuzzling\Web\Value\DataImport\ImportRowRefusal;

/**
 * Before an "Add" (docs/features/data-import/adding-screens.md "Already on MySpeedPuzzling, asked before the Add"):
 * how many ready results the file says are on MySpeedPuzzling already **and the "Add" would send back with that
 * question** - asked of the engine itself, row by row (ImportRowAdder::openQuestionsOf(): the steps an "Add" takes,
 * never a second rule). A result that is found on the profile is not counted: it is simply not added twice.
 *
 * Each row costs a few statements, so it is bounded (ROWS_CHECKED) and kept off the review page - a frame of it.
 * A row it did not come to is still asked about when it is added.
 */
readonly final class ImportSaidAlreadyCheck
{
    public const int ROWS_CHECKED = 500;

    public function __construct(
        private DataImportRowRepository $rowRepository,
        private ImportRowAdder $rowAdder,
    ) {
    }

    /**
     * @return array{said: int, notFound: int, allChecked: bool, rowIds: list<string>}
     *         `said`: the ready results the file says are on MySpeedPuzzling that were looked at; `notFound`: of
     *         them, those the "Add" would ask about (`rowIds`); `allChecked`: there are no more than were looked at
     */
    public function of(DataImport $import): array
    {
        $ids = $this->rowRepository->idsOfSaidAlreadyReady($import, null, self::ROWS_CHECKED + 1);
        $allChecked = count($ids) <= self::ROWS_CHECKED;
        $ids = array_slice($ids, 0, self::ROWS_CHECKED);
        $notFound = [];

        foreach ($this->rowRepository->ofImportByIds($import, $ids) as $rowId => $row) {
            if (in_array(ImportRowRefusal::SaidAlreadyOnProfile->value, $this->rowAdder->openQuestionsOf($row, false), true)) {
                $notFound[] = $rowId;
            }
        }

        return ['said' => count($ids), 'notFound' => count($notFound), 'allChecked' => $allChecked, 'rowIds' => $notFound];
    }
}
