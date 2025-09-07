<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\Validator\Constraints\Length;

final class AddPuzzleToCollectionFormData
{
    public null|string $collectionId = null;

    #[Length(max: 500)]
    public null|string $comment = null;
}
