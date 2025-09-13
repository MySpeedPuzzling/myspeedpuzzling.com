<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Value\CollectionVisibility;
use Symfony\Component\Validator\Constraints\Length;

final class AddPuzzleToCollectionFormData
{
    #[Length(max: 100)]
    public null|string $collection = null;

    #[Length(max: 500)]
    public null|string $collectionDescription = null;

    public CollectionVisibility $collectionVisibility = CollectionVisibility::Private;

    #[Length(max: 500)]
    public null|string $comment = null;
}
