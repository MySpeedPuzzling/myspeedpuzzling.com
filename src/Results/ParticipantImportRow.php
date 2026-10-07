<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\ParticipantImportRowAction;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * One row of the uploaded file in the import preview.
 */
readonly final class ParticipantImportRow
{
    /**
     * @param list<array{field: string, before: null|string, after: null|string}> $changes field = translation key suffix
     *        of `competition.participants.import.field.*` (name, country, external_id, msp_player_id)
     * @param list<string> $roundsAdded round names the row adds the person to
     * @param list<string> $roundsRemoved round names full sync takes the person out of (only on the person's first row)
     * @param array<string, null|string> $teams round name => team name after the import, for the row's pair/team rounds
     *        (null = no team in that round)
     * @param list<TranslatableMessage> $messages what the organiser should know about this row
     */
    public function __construct(
        public int $rowNumber,
        public string $name,
        public ParticipantImportRowAction $action,
        /** The matched participant, null for a new one or a skipped row */
        public null|string $participantId = null,
        public array $changes = [],
        public array $roundsAdded = [],
        public array $roundsRemoved = [],
        public array $teams = [],
        public array $messages = [],
        /** When a removed participant is restored: when they were removed */
        public null|\DateTimeImmutable $removedAt = null,
    ) {
    }

    public function hasWarning(): bool
    {
        return $this->messages !== [];
    }
}
