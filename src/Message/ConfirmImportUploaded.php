<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * "The file is up": the object is checked (it exists, with exactly the size the upload was signed for) and the
 * import moves from `uploading` to `uploaded` (docs/features/data-import/upload.md). Sent twice, the second one
 * changes nothing.
 */
readonly final class ConfirmImportUploaded
{
    public function __construct(
        public string $importId,
        public string $playerId,
    ) {
    }
}
