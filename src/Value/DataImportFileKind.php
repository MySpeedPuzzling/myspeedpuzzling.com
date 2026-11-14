<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The kinds of file an import takes (docs/features/data-import/README.md "Reading a file": `.xlsx` and
 * `.csv`/`.tsv`). The kind comes from the file's name only - what the bytes really are is the reader's question, in
 * the worker. The content type is ours, never the browser's: browsers send none or a wrong one for these files, and
 * the upload is signed for exactly this one.
 */
enum DataImportFileKind: string
{
    case Xlsx = 'xlsx';
    case Csv = 'csv';
    case Tsv = 'tsv';

    public static function ofFileName(string $fileName): null|self
    {
        $dot = strrpos($fileName, '.');

        if ($dot === false) {
            return null;
        }

        return self::tryFrom(strtolower(substr($fileName, $dot + 1)));
    }

    public function contentType(): string
    {
        return match ($this) {
            self::Xlsx => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            self::Csv => 'text/csv',
            self::Tsv => 'text/tab-separated-values',
        };
    }

    /**
     * The file picker's `accept`
     */
    public static function acceptAttribute(): string
    {
        return implode(',', array_map(static fn (self $kind): string => '.' . $kind->value, self::cases()));
    }
}
