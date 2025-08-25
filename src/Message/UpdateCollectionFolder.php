<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class UpdateCollectionFolder
{
    public function __construct(
        public string $folderId,
        public string $name,
        public null|string $color = null,
        public null|string $description = null,
    ) {
    }
}