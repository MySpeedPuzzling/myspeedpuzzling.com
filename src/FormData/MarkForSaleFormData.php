<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\Validator\Constraints as Assert;

final class MarkForSaleFormData
{
    #[Assert\NotBlank(message: 'Please select a sale type')]
    #[Assert\Choice(choices: ['lend', 'sell'], message: 'Invalid sale type')]
    public string $saleType = 'sell';

    #[Assert\Type(type: 'numeric', message: 'Price must be a number')]
    #[Assert\PositiveOrZero(message: 'Price must be positive')]
    public null|string $price = null;

    #[Assert\Length(exactly: 3, exactMessage: 'Currency must be a 3-letter code')]
    #[Assert\Regex(pattern: '/^[A-Z]{3}$/', message: 'Currency must be a valid 3-letter code')]
    public null|string $currency = 'USD';

    #[Assert\Length(max: 255, maxMessage: 'Condition description is too long')]
    public null|string $condition = null;

    #[Assert\Length(max: 500, maxMessage: 'Comment is too long')]
    public null|string $comment = null;
}
