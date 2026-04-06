<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;

readonly final class AddRoundTable
{
    public function __construct(
        public UuidInterface $tableId,
        public string $rowId,
    ) {
    }
}
