<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A submitted section form: what the organiser typed (shown again when the form is refused) and what is wrong with it.
 */
readonly final class PageSectionSubmission
{
    /**
     * @param array<string, mixed> $content the type's payload, blank rows left out - sanitised by the handler
     * @param list<array{key: string, parameters: array<string, int|string>}> $errors translation keys (messages domain)
     */
    public function __construct(
        public string $title,
        public array $content,
        public array $errors,
    ) {
    }

    public function isValid(): bool
    {
        return $this->errors === [];
    }
}
