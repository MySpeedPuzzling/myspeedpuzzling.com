<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Value\SheetChangeStatus;

/**
 * The server's answer to one group of a participants sheet change set: `applied` (at least one change went through),
 * `unchanged` (everything was there already), `conflict` (a change found another value - nothing of the group applied)
 * or `refused` (a rule refused a change - nothing of the group applied). `deletedTeams` = pairs/teams without a name the
 * group emptied, deleted with it (participants-spreadsheet.md §5).
 */
readonly final class SheetGroupOutcome
{
    /**
     * @param list<SheetChangeOutcome> $changes in the order of the group
     * @param list<SheetWarning> $warnings
     * @param list<string> $deletedTeams
     */
    public function __construct(
        public string $id,
        public SheetChangeStatus $status,
        public array $changes,
        public array $warnings = [],
        public array $deletedTeams = [],
    ) {
    }

    /**
     * @return array{id: string, status: string, changes: list<array<string, mixed>>, warnings: list<array<string, mixed>>, deletedTeams: list<string>}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'status' => $this->status->value,
            'changes' => array_map(static fn (SheetChangeOutcome $change): array => $change->toArray(), $this->changes),
            'warnings' => array_map(static fn (SheetWarning $warning): array => $warning->toArray(), $this->warnings),
            'deletedTeams' => $this->deletedTeams,
        ];
    }

    /**
     * @param array<mixed> $data
     */
    public static function fromArray(array $data): self
    {
        $id = $data['id'] ?? '';
        $status = $data['status'] ?? null;
        $changes = is_array($data['changes'] ?? null) ? $data['changes'] : [];
        $warnings = is_array($data['warnings'] ?? null) ? $data['warnings'] : [];
        $deletedTeams = is_array($data['deletedTeams'] ?? null) ? $data['deletedTeams'] : [];

        return new self(
            id: is_string($id) ? $id : '',
            status: is_string($status) ? (SheetChangeStatus::tryFrom($status) ?? SheetChangeStatus::Refused) : SheetChangeStatus::Refused,
            changes: array_values(array_map(
                static fn (array $change): SheetChangeOutcome => SheetChangeOutcome::fromArray($change),
                array_filter($changes, is_array(...)),
            )),
            warnings: array_values(array_map(
                static fn (array $warning): SheetWarning => SheetWarning::fromArray($warning),
                array_filter($warnings, is_array(...)),
            )),
            deletedTeams: array_values(array_filter($deletedTeams, is_string(...))),
        );
    }
}
