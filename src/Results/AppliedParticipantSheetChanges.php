<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use SpeedPuzzling\Web\Entity\ParticipantSheetChangeReceipt;

/**
 * What ApplyParticipantSheetChanges did - an answer per group, and the sheet's state version (GetParticipantsSheetVersion)
 * under the event's lock before the change set and after its write. A page that knew `versionBefore` knows that its
 * own model now is the server's, at `versionAfter`; any other version means somebody else changed the sheet meanwhile
 * (participants-spreadsheet.md, delivery contract §3.2). `replayed` = answered from the receipt of a change set id seen
 * before, nothing applied now.
 */
readonly final class AppliedParticipantSheetChanges
{
    /**
     * @param list<SheetGroupOutcome> $groups
     */
    public function __construct(
        public bool $dryRun,
        public bool $replayed,
        public string $versionBefore,
        public string $versionAfter,
        public array $groups,
    ) {
    }

    public static function fromReceipt(ParticipantSheetChangeReceipt $receipt): self
    {
        return new self(
            dryRun: false,
            replayed: true,
            versionBefore: $receipt->versionBefore,
            versionAfter: $receipt->versionAfter,
            groups: array_map(
                static fn (array $group): SheetGroupOutcome => SheetGroupOutcome::fromArray($group),
                $receipt->outcomes,
            ),
        );
    }

    /**
     * The groups as a receipt keeps them.
     *
     * @return list<array<string, mixed>>
     */
    public function outcomes(): array
    {
        return array_map(static fn (SheetGroupOutcome $group): array => $group->toArray(), $this->groups);
    }

    /**
     * The sheet changed now - the other open pages are told (ParticipantsSheetLiveUpdates). A change set whose changes
     * net to nothing (taken out and put back) changes no version and tells nobody.
     */
    public function changedTheSheet(): bool
    {
        return $this->dryRun === false && $this->replayed === false && $this->versionAfter !== $this->versionBefore;
    }
}
