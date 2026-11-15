<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\DataImportStatus;

/**
 * One of the signed-in player's imports in progress - what "finish or discard one first" links to
 * (Services\DataImport\ImportSlots).
 */
readonly final class ImportHoldingAPlace
{
    public function __construct(
        public string $importId,
        /** '' for a Google sheet whose title is not known (yet) */
        public string $fileName,
        public DataImportStatus $status,
    ) {
    }
}
