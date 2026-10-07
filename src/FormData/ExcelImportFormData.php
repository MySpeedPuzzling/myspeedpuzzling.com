<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

/**
 * The participant list upload (docs/features/competitions-management/participant-import-preview.md, D1). The messages
 * are keys of the `messages` domain: ImportCompetitionParticipantsController shows them as a flash. The extension is
 * checked by the controller (ParticipantFileFormat::fromFileName()), the content by ParticipantFileReader.
 */
final class ExcelImportFormData
{
    public const string MAX_SIZE = '5M';

    /**
     * What browsers' and libmagic's guesses for these files look like: any text (libmagic calls some CSVs C or Algol
     * source), the xlsx type, a zip, and the types Windows and some converters give a CSV or an xlsx.
     */
    public const array MIME_TYPES = [
        'text/*',
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
        'application/vnd.ms-excel',
        'application/csv',
        'application/x-csv',
        'application/octet-stream',
    ];

    #[NotBlank(message: 'competition.participants.import.upload.choose_file')]
    #[File(
        maxSize: self::MAX_SIZE,
        mimeTypes: self::MIME_TYPES,
        maxSizeMessage: 'competition.participants.import.upload.too_large',
        mimeTypesMessage: 'competition.participants.import.upload.invalid_type',
        uploadIniSizeErrorMessage: 'competition.participants.import.upload.too_large',
        uploadFormSizeErrorMessage: 'competition.participants.import.upload.too_large',
        uploadErrorMessage: 'competition.participants.import.upload.failed',
        disallowEmptyMessage: 'competition.participants.import.file.empty',
    )]
    public null|UploadedFile $file = null;
}
