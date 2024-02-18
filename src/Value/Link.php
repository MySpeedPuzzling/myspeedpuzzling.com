<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

readonly final class Link
{
    public function __construct(
        public null|string $link,
        public string $text,
    ) {
    }
}
