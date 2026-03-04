<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

use Ramsey\Uuid\UuidInterface;

readonly final class AddTableRow
{
    public function __construct(
        public UuidInterface $rowId,
        public string $roundId,
    ) {
    }
}
