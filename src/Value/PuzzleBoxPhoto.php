<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Symfony\Component\Validator\Constraints\Image;

/**
 * What a photo of a puzzle box must be - the one rule for every place a new
 * puzzle is created (the add form, multiscan quick-add). A new puzzle always
 * comes with one: AddPuzzle requires it.
 */
final class PuzzleBoxPhoto
{
    public const string MAX_SIZE = '10m';

    public static function constraint(): Image
    {
        return new Image(
            maxSize: self::MAX_SIZE,
            mimeTypes: [
                'image/jpeg',
                'image/png',
                'image/gif',
                'image/webp',
                'image/heic',
                'image/heif',
                'image/avif',
            ],
            mimeTypesMessage: 'image_invalid_mime_type',
        );
    }
}
