<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * "Start over for this sheet" (docs/features/data-import/layouts.md): the sheet's mapping is proposed again from
 * its own rows, without the layout the player saved earlier - a new `mapping_version`, its rows read again. The
 * saved layout itself stays as it is.
 */
readonly final class StartImportSheetOver
{
    /**
     * @param int $mappingVersion the version the player looked at - a newer one in the database refuses the change
     */
    public function __construct(
        public string $importId,
        public string $playerId,
        public string $sheetId,
        public int $mappingVersion,
    ) {
    }
}
