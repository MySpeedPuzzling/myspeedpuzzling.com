<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Chosen by the organiser on the import preview (docs/features/competitions-management/participant-import-preview.md D7).
 */
enum ParticipantImportMode: string
{
    /** Add new people, update existing ones - nothing is removed (the default) */
    case Update = 'update';

    /** The file is the truth: also removes participants, round entries and pairs/teams that are not in the file */
    case Sync = 'sync';
}
