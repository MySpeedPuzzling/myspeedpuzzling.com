<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Entity\CollectionItem;
use Symfony\Component\Validator\Constraints\Length;

final class EditCollectionItemCommentFormData
{
    #[Length(max: 500)]
    public null|string $comment = null;

    public static function fromCollectionItem(CollectionItem $item): self
    {
        $data = new self();
        $data->comment = $item->comment;

        return $data;
    }
}
