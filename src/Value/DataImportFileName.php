<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The name of an import's file as it is kept: shown on the player's own pages only, never part of the object's key.
 * One rule for a name the browser sent and for one Google sent.
 */
final class DataImportFileName
{
    public static function clean(string $name): string
    {
        // Bytes that are no text are dropped instead of breaking the insert
        $name = mb_scrub($name, 'UTF-8');

        return mb_substr(trim(str_replace(["\r", "\n", "\t", "\0", '/', '\\'], ' ', $name)), 0, DataImportLimits::FILE_NAME_MAX_LENGTH);
    }
}
