<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

readonly final class EditPageSection
{
    /**
     * @param array<string, mixed> $content
     */
    public function __construct(
        public string $sectionId,
        public null|string $title,
        public array $content,
    ) {
    }
}
