<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Value\CollectionVisibility;
use Symfony\Component\Validator\Constraints as Assert;

final class EditLendBorrowListSettingsFormData
{
    #[Assert\NotBlank]
    public null|CollectionVisibility $visibility = null;

    public static function fromVisibility(CollectionVisibility $visibility): self
    {
        $data = new self();
        $data->visibility = $visibility;

        return $data;
    }
}
