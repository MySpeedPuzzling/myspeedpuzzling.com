<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use SpeedPuzzling\Web\Value\ListingType;
use SpeedPuzzling\Web\Value\PuzzleCondition;
use Symfony\Component\Validator\Constraints as Assert;

final class AddToSellSwapListFormData
{
    #[Assert\NotBlank]
    public null|ListingType $listingType = null;

    #[Assert\PositiveOrZero]
    public null|float $price = null;

    #[Assert\NotBlank]
    public null|PuzzleCondition $condition = null;

    #[Assert\Length(max: 500)]
    public null|string $comment = null;
}
