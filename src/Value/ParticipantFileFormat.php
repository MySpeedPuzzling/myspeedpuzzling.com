<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What an uploaded participant list is read as (docs/features/competitions-management/participant-import-preview.md, D1).
 * Decided by the file name only - the reader refuses a file whose content does not fit.
 */
enum ParticipantFileFormat: string
{
    case Xlsx = 'xlsx';
    case Csv = 'csv';

    /**
     * Every file name extension the upload accepts.
     */
    public const array EXTENSIONS = ['xlsx', 'csv', 'tsv', 'txt'];

    public static function fromFileName(string $fileName): null|self
    {
        return match (strtolower(pathinfo($fileName, PATHINFO_EXTENSION))) {
            'xlsx' => self::Xlsx,
            'csv', 'tsv', 'txt' => self::Csv,
            default => null,
        };
    }
}
