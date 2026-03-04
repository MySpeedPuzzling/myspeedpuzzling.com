<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;

readonly final class AddTableSpot
{
    public function __construct(
        public UuidInterface $spotId,
        public string $tableId,
    ) {
    }
}
