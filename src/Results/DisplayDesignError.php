<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * One reason a display design was refused (DisplayDesignSanitizer, D-displays-overlays.md §4): a JSON pointer into the
 * design the editor highlights ("" = the whole design) and a code the save endpoint translates
 * (`competition_display.design_error.<code>`). assets/displays/schema.js reports the same pairs.
 */
readonly final class DisplayDesignError
{
    public function __construct(
        public string $path,
        public string $code,
    ) {
    }

    /**
     * @return array{path: string, code: string}
     */
    public function toArray(): array
    {
        return ['path' => $this->path, 'code' => $this->code];
    }
}
