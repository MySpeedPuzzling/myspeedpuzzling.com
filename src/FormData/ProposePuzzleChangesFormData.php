<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Image;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\NotBlank;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\Range;

final class ProposePuzzleChangesFormData
{
    #[NotBlank]
    #[Length(max: 255)]
    public string $name = '';

    public null|string $manufacturerId = null;

    #[NotBlank]
    #[Positive]
    #[Range(min: 10, max: 25000)]
    public int $piecesCount = 0;

    #[Length(max: 100)]
    public null|string $ean = null;

    #[Length(max: 100)]
    public null|string $identificationNumber = null;

    #[Image(
        maxSize: '20M',
        mimeTypes: ['image/jpeg', 'image/png', 'image/webp'],
        mimeTypesMessage: 'Please upload a valid image (JPEG, PNG, or WebP, up to 20 MB).'
    )]
    public null|UploadedFile $photo = null;
}
