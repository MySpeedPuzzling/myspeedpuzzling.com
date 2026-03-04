<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\FormData;

use Symfony\Component\Validator\Constraints as Assert;

final class GenerateTableLayoutFormData
{
    public function __construct(
        #[Assert\NotNull]
        #[Assert\Positive]
        #[Assert\LessThanOrEqual(20)]
        public null|int $numberOfRows = null,
        #[Assert\NotNull]
        #[Assert\Positive]
        #[Assert\LessThanOrEqual(20)]
        public null|int $tablesPerRow = null,
        #[Assert\NotNull]
        #[Assert\Positive]
        #[Assert\LessThanOrEqual(10)]
        public null|int $spotsPerTable = null,
    ) {
    }
}
