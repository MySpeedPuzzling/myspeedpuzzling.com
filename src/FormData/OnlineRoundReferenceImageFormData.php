<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Value\PhotoUploadLimits;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * The organiser's reference image of an online round for the completeness check (online_round_settings,
 * docs/features/online-competitions/README.md §5.8): the site's usual photo rule - the types and the size of a puzzle
 * photo (PhotoUploadLimits, as PuzzleBoxPhoto); a file that is not there is said by the controller, a damaged picture by
 * the handler (OnlineReferenceImageUnreadable).
 */
final class OnlineRoundReferenceImageFormData
{
    #[Assert\Image(
        maxSize: PhotoUploadLimits::MAX_PHOTO_BYTES,
        mimeTypes: PhotoUploadLimits::MIME_TYPES,
        mimeTypesMessage: 'image_invalid_mime_type',
    )]
    public null|UploadedFile $image = null;
}
