<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\PuzzleProposalField;

/**
 * What the pending proposals of a puzzle keep a new one from changing (GetPendingPuzzleProposals::locks()): a field a
 * pending change request changes is locked until a moderator decides it, a pending merge request locks every field.
 * The names are never locked (PuzzleProposalField::locksWhilePending()).
 */
readonly final class PendingProposalLocks
{
    /**
     * @param array<string, PendingFieldProposal> $waiting the locked fields (PuzzleProposalField values) with what is
     *                                                     proposed for them - the oldest pending proposal of each
     */
    public function __construct(
        public bool $mergeRequestPending,
        public array $waiting,
    ) {
    }

    public function isLocked(PuzzleProposalField $field): bool
    {
        return $field->locksWhilePending() && ($this->mergeRequestPending || isset($this->waiting[$field->value]));
    }

    public function waitingFor(PuzzleProposalField $field): null|PendingFieldProposal
    {
        return $this->waiting[$field->value] ?? null;
    }

    /**
     * isLocked() by the field's name (a PuzzleProposalField value) - for the templates.
     */
    public function fieldLocked(string $field): bool
    {
        return $this->isLocked(PuzzleProposalField::from($field));
    }

    public function waitingForField(string $field): null|PendingFieldProposal
    {
        return $this->waitingFor(PuzzleProposalField::from($field));
    }

    /**
     * Nothing but the names can be proposed now - the form shows the pending proposals instead.
     */
    public function locksEveryField(): bool
    {
        foreach (PuzzleProposalField::lockable() as $field) {
            if ($this->isLocked($field) === false) {
                return false;
            }
        }

        return true;
    }

    public function locksAnyField(): bool
    {
        return $this->mergeRequestPending || $this->waiting !== [];
    }

    /**
     * @param list<PuzzleProposalField> $fields what a new proposal changes
     * @return list<PuzzleProposalField> those of them that are locked
     */
    public function lockedAmong(array $fields): array
    {
        return array_values(array_filter($fields, $this->isLocked(...)));
    }
}
