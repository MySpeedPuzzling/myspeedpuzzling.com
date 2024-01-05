<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

readonly final class SearchQuery
{
    public string $value;

    public function __construct(
        string $query,
    ) {
        $this->value = preg_replace("/[^A-Za-z0-9\p{L}\s]/u", '', $query) ?? '';
    }
}
