<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class DeletePuzzleCollection
{
    public function __construct(
        public string $collectionId,
    ) {
    }
}