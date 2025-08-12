<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class DeleteCollectionFolder
{
    public function __construct(
        public string $folderId,
    ) {
    }
}