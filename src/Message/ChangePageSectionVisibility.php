<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Message;

/**
 * Shows or hides one section without deleting it (draft content, seasonal sections).
 */
readonly final class ChangePageSectionVisibility
{
    public function __construct(
        public string $sectionId,
        public bool $visible,
    ) {
    }
}
