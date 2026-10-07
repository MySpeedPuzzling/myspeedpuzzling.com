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
        /** Participants on the file the import did not change */
        public int $unchanged = 0,
        /** How many of the `updated` ones were removed participants the file brought back */
        public int $restored = 0,
        /** Participants full sync removed (soft-deleted) - not in the file; `softDeleted` counts `status = deleted` rows */
        public int $removed = 0,
        /** Round entries full sync removed */
        public int $roundEntriesRemoved = 0,
        /** Pairs/teams full sync deleted - the import emptied them */
        public int $teamsRemoved = 0,
    ) {
    }

    public function hasIssues(): bool
    {
        return count($this->warnings) > 0 || count($this->errors) > 0;
    }
}
