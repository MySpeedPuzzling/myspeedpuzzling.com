<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Results\CollectionOverview;
use SpeedPuzzling\Web\Value\CollectionVisibility;
use Symfony\Component\Validator\Constraints as Assert;

final class CollectionFormData
{
    #[Assert\NotBlank]
    #[Assert\Length(max: 100)]
    public null|string $name = null;

    #[Assert\Length(max: 500)]
    public null|string $description = null;

    public CollectionVisibility $visibility = CollectionVisibility::Private;

    // Preselected by every collection picker (docs/features/collections/default-collection.md); unticked = the system collection
    public bool $isDefault = false;

    public static function fromCollectionOverview(CollectionOverview $collection): self
    {
        $data = new self();
        $data->name = $collection->name;
        $data->description = $collection->description;
        $data->visibility = $collection->visibility;

        return $data;
    }
}
