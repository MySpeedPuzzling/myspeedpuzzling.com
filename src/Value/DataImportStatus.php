<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Where an import stands (docs/features/data-import/README.md "Data model", docs/features/data-import/upload.md).
 *
 * `uploading` = the browser was told where to put the file and has not confirmed it yet; `uploaded` = the file is in
 * object storage, checked. Reading the file (`reading` and everything after it) arrives with the reader - until then
 * `uploaded` is where an import rests.
 */
enum DataImportStatus: string
{
    case Uploading = 'uploading';
    case Uploaded = 'uploaded';
    case Reading = 'reading';
    case SettingUp = 'setting_up';
    case Reviewing = 'reviewing';
    case Done = 'done';
    case Discarded = 'discarded';

    /**
     * The imports that count against DataImportLimits::MAX_UNFINISHED_PER_PLAYER: the file arrived and the import is
     * neither finished nor discarded.
     *
     * @return list<self>
     */
    public static function unfinished(): array
    {
        return [self::Uploaded, self::Reading, self::SettingUp, self::Reviewing];
    }

    /**
     * What a new upload is counted against: the unfinished imports and the uploads on their way - else ten uploads
     * started side by side would all pass the count and all be confirmed.
     *
     * @return list<self>
     */
    public static function holdingAPlace(): array
    {
        return [self::Uploading, ...self::unfinished()];
    }
}
