<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What the section forms need to know about the page they edit.
 */
readonly final class PageSectionOwnerOverview
{
    public function __construct(
        public string $name,
        public bool $isOnline,
    ) {
    }
}
