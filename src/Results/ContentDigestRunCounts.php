<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

readonly final class ContentDigestRunCounts
{
    public function __construct(
        public int $sent,
        public int $failedPermanently,
        public int $deferred,
    ) {
    }

    public function failures(): int
    {
        return $this->failedPermanently + $this->deferred;
    }

    public function attempts(): int
    {
        return $this->sent + $this->failures();
    }
}
