<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * A player brings a spreadsheet (docs/features/data-import/upload.md): the import is created in the state
 * `uploading`, with the place in object storage its file is expected at. Nothing has arrived yet - the browser's
 * presigned upload (or the fallback form) puts the file there, ConfirmImportUploaded checks it.
 */
readonly final class StartImport
{
    public function __construct(
        public string $importId,
        public string $playerId,
        public string $fileName,
        public int $fileSize,
    ) {
    }
}
