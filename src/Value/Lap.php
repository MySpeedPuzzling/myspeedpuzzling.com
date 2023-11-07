<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use DateTimeImmutable;

readonly final class Lap
{
    private function __construct(
        public DateTimeImmutable $start,
        public null|DateTimeImmutable $end,
    ) {
    }

    public static function start(DateTimeImmutable $now): self
    {
        return new self($now, null);
    }

    public function finish(DateTimeImmutable $now): self
    {
        return new self($this->start, $now);
    }
}
