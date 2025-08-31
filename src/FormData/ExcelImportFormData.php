<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\File;
use Symfony\Component\Validator\Constraints\NotBlank;

final class ExcelImportFormData
{
    #[NotBlank(message: 'Please choose an .xlsx file.')]
    #[File(
        maxSize: '20M',
        mimeTypes: [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            // some environments report XLSX as zip
            'application/zip',
        ],
        mimeTypesMessage: 'Please upload a valid .xlsx file (.xlsx, up to 20 MB).'
    )]
    public null|UploadedFile $file = null;
}
