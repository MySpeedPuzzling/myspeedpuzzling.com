<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * One change sent by an organiser's device (RecordRoundResults): set `field` of one round entry from `from` (the
 * value the device last saw) to `to`. The entry is an existing one (`target`) or one created by this change
 * (`newEntry`). A change the parser could not read carries only its id and `rejectedReason`.
 *
 * Values are RoundEntryResult for the result, null|int for the table number, bool for the qualified mark,
 * null|RoundEntryPuzzles for the puzzles of a `pick` round entry.
 *
 * Time proposals (competition toolkit P1, R-review-and-plan.md §1.2): a `result` change may carry `proposalId` - the
 * competitor's proposal it confirms; a `proposal` change (a judge's "Not right") carries the proposal in `proposalId` and
 * the judge's reason in `proposalRejection`, its `from` / `to` stay null.
 */
final readonly class RoundResultChange
{
    private function __construct(
        public string $clientChangeId,
        public null|RoundEntryRef $target,
        public null|NewRoundEntry $newEntry,
        public null|RoundResultField $field,
        public null|RoundEntryResult|RoundEntryPuzzles|int|bool $from,
        public null|RoundEntryResult|RoundEntryPuzzles|int|bool $to,
        public null|string $rejectedReason,
        public null|string $proposalId = null,
        public null|TimeProposalDecisionReason $proposalRejection = null,
    ) {
    }

    public static function forEntry(
        string $clientChangeId,
        RoundEntryRef|NewRoundEntry $entry,
        RoundResultField $field,
        null|RoundEntryResult|RoundEntryPuzzles|int|bool $from,
        null|RoundEntryResult|RoundEntryPuzzles|int|bool $to,
        null|string $proposalId = null,
    ): self {
        return new self(
            clientChangeId: strtolower($clientChangeId),
            target: $entry instanceof RoundEntryRef ? $entry : null,
            newEntry: $entry instanceof NewRoundEntry ? $entry : null,
            field: $field,
            from: $from,
            to: $to,
            rejectedReason: null,
            proposalId: $proposalId !== null ? strtolower($proposalId) : null,
        );
    }

    /**
     * A judge's rejection of a competitor's time proposal (`field: "proposal"`)
     */
    public static function proposalRejection(string $clientChangeId, RoundEntryRef $entry, string $proposalId, TimeProposalDecisionReason $reason): self
    {
        return new self(
            clientChangeId: strtolower($clientChangeId),
            target: $entry,
            newEntry: null,
            field: RoundResultField::Proposal,
            from: null,
            to: null,
            rejectedReason: null,
            proposalId: strtolower($proposalId),
            proposalRejection: $reason,
        );
    }

    public static function unreadable(string $clientChangeId, string $reason): self
    {
        return new self(strtolower($clientChangeId), null, null, null, null, null, $reason);
    }

    public function entryRef(): null|RoundEntryRef
    {
        return $this->target ?? $this->newEntry?->ref();
    }
}
