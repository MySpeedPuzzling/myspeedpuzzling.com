<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\ParticipantImportMode;
use SpeedPuzzling\Web\Value\ParticipantImportRowAction;
use Symfony\Component\Translation\TranslatableMessage;

/**
 * What confirming an import will do - made by ParticipantImportPlanner without writing anything, shown on the
 * preview, and made again by the confirm handler, which applies it only when its fingerprint is still the same.
 */
readonly final class ParticipantImportPlan
{
    /**
     * @param list<ParticipantImportRow> $rows every row of the file, in file order
     * @param list<TranslatableMessage> $warnings grouped (one per kind and value, rows listed in the message)
     * @param list<TranslatableMessage> $errors nothing can be imported while there are errors (e.g. no name column)
     * @param object $operations what ParticipantImportApplier writes - opaque to everybody else
     */
    public function __construct(
        public ParticipantImportMode $mode,
        public array $rows,
        public array $warnings,
        public array $errors,
        public ParticipantImportRemovals $removals,
        public object $operations,
        public string $fingerprint,
    ) {
    }

    public function count(ParticipantImportRowAction $action): int
    {
        return count(array_filter($this->rows, static fn (ParticipantImportRow $row): bool => $row->action === $action));
    }

    public function canBeApplied(): bool
    {
        return $this->errors === [];
    }

    /**
     * Full sync removes what is listed; "Update only" keeps it.
     */
    public function removesAnything(): bool
    {
        return $this->mode === ParticipantImportMode::Sync && !$this->removals->isEmpty();
    }
}
