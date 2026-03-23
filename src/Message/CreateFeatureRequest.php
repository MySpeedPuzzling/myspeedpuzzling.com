<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class CreateFeatureRequest
{
    public function __construct(
        public string $authorId,
        public string $title,
        public string $description,
    ) {
    }
}
