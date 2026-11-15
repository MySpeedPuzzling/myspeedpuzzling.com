<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value\DataImport;

use DateTimeImmutable;
use SpeedPuzzling\Web\Value\DataImportLimits;
use SpeedPuzzling\Web\Value\DataImportStatus;

/**
 * What is left to do with one import (Services\DataImport\ImportSlots::standingOf()) - the one answer to "does it
 * hold one of the player's places?" and "may the daily sweep finish it?" (docs/features/data-import/upload.md
 * "Finished").
 */
readonly final class ImportStanding
{
    public function __construct(
        public DataImportStatus $status,
        /** A row of it was ever taken by an "Add": it can never be discarded (adding.md "What is never deleted") */
        public bool $tookRows,
        /** An "Add", an Undo or one answer for many rows is running */
        public bool $working,
        /** A row is ready to be added - the engine's own definition (DataImportRowRepository::sqlReadyRows()) */
        public bool $hasReady,
        /** Something waits for the player: a puzzle to choose, a row to look at or to give a date, a sheet's question */
        public bool $hasWaiting,
        /** The last thing that happened to it: the player's answers, an "Add", an Undo */
        public DateTimeImmutable $activeAt,
    ) {
    }

    /**
     * Every row is settled - added, already the player's, left out - and the import can not be discarded any more.
     */
    public function isSettled(): bool
    {
        return in_array($this->status, [DataImportStatus::SettingUp, DataImportStatus::Reviewing], true)
            && $this->tookRows
            && $this->working === false
            && $this->hasReady === false
            && $this->hasWaiting === false;
    }

    /**
     * One of the player's DataImportLimits::MAX_UNFINISHED_PER_PLAYER places. An upload on its way is the caller's
     * to count or not.
     */
    public function holdsAPlace(): bool
    {
        return in_array($this->status, DataImportStatus::holdingAPlace(), true) && $this->isSettled() === false;
    }

    /**
     * The daily sweep finishes it: settled and at rest for DataImportLimits::FINISHED_AFTER_SETTLED_HOURS hours, or
     * nothing is ready and what waits for the player has waited for DataImportLimits::FINISHED_AFTER_WAITING_DAYS
     * days without anything happening to the import.
     */
    public function isFinishedBySweep(DateTimeImmutable $now): bool
    {
        if ($this->isSettled()) {
            return $this->activeAt < $now->modify('-' . DataImportLimits::FINISHED_AFTER_SETTLED_HOURS . ' hours');
        }

        return in_array($this->status, [DataImportStatus::SettingUp, DataImportStatus::Reviewing], true)
            && $this->tookRows
            && $this->working === false
            && $this->hasReady === false
            && $this->activeAt < $now->modify('-' . DataImportLimits::FINISHED_AFTER_WAITING_DAYS . ' days');
    }
}
