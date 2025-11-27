<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Value\CollectionVisibility;
use Symfony\Component\HttpFoundation\File\UploadedFile;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Positive;
use Symfony\Component\Validator\Constraints\Range;

final class AddNewPuzzleToCollectionFormData
{
    public null|string $brand = null;

    public null|string $puzzle = null;

    #[Positive]
    #[Range(min: 10, max: 25000)]
    public null|int $puzzlePiecesCount = null;

    public null|UploadedFile $puzzlePhoto = null;

    #[Length(max: 15)]
    public null|string $puzzleEan = null;

    #[Length(max: 50)]
    public null|string $puzzleIdentificationNumber = null;

    #[Length(max: 100)]
    public null|string $collection = null;

    #[Length(max: 500)]
    public null|string $collectionDescription = null;

    public CollectionVisibility $collectionVisibility = CollectionVisibility::Private;

    #[Length(max: 500)]
    public null|string $comment = null;
}
