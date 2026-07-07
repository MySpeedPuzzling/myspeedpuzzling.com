<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\PageSectionType;

/**
 * One renderable entry of a competition public page: either a system section
 * (identified by key) or a manager-authored content section.
 */
readonly final class PageEntry
{
    /**
     * @param array<string, mixed> $content
     */
    public function __construct(
        public string $key,
        public bool $isSystem,
        public bool $visible,
        public bool $inherited = false,
        public null|string $sectionId = null,
        public null|PageSectionType $type = null,
        public null|string $title = null,
        public array $content = [],
    ) {
    }
}
