<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use RuntimeException;

/**
 * An import's file is not taken (docs/features/data-import/upload.md): `empty`, `too_large`, `type` (not .xlsx, .csv
 * or .tsv), `too_many` (unfinished imports of the player) at the start; `missing` (no object where the upload was
 * signed for), `size` (an object of another size) and `state` (the import is past its upload) at the confirmation.
 */
final class ImportUploadRefused extends RuntimeException
{
    public const array REASONS = ['empty', 'too_large', 'type', 'too_many', 'missing', 'size', 'state'];

    public function __construct(
        public readonly string $reason,
    ) {
        parent::__construct('Import upload refused: ' . $reason);
    }
}
