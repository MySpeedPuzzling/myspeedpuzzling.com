<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Symfony\Component\Validator\Constraints\Image;

/**
 * What the photo of a finished puzzle on a result must be - the add and the edit form.
 */
final class FinishedPuzzlePhoto
{
    public static function constraint(): Image
    {
        return new Image(
            maxSize: PhotoUploadLimits::MAX_PHOTO_BYTES,
            mimeTypes: PhotoUploadLimits::MIME_TYPES,
            mimeTypesMessage: 'image_invalid_mime_type',
        );
    }
}
