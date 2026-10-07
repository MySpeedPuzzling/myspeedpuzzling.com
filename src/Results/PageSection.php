<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\PageSectionType;

/**
 * One organiser-written section as a page or the page editor shows it. `inherited` = a series section on an edition.
 */
readonly final class PageSection
{
    /**
     * @param array<string, mixed> $content sanitised payload of the type (PageSectionContentSanitizer)
     */
    public function __construct(
        public string $id,
        public PageSectionType $type,
        public null|string $title,
        public array $content,
        public bool $visible,
        public bool $inherited,
    ) {
    }
}
