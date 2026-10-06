<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use Symfony\Component\Translation\TranslatableMessage;

readonly final class ParticipantImportResult
{
    /**
     * Messages for the organiser - translate them (`competition.participants.import.*`).
     *
     * @param array<TranslatableMessage> $warnings
     * @param array<TranslatableMessage> $errors
     */
    public function __construct(
        public int $added = 0,
        public int $updated = 0,
        public int $softDeleted = 0,
        public array $warnings = [],
        public array $errors = [],
    ) {
    }

    public function hasIssues(): bool
    {
        return count($this->warnings) > 0 || count($this->errors) > 0;
    }
}
