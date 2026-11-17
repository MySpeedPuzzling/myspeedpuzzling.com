<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What ProposeRoundTimeHandler / WithdrawTimeProposalHandler did (read it from the envelope's HandledStamp):
 * - proposed: a new pending proposal was stored
 * - withdrawn: the pending proposal is withdrawn now
 * - known: nothing changed - a resend of a proposal stored before, or a withdrawal of one no longer pending
 * - refused: nothing changed, `reason` says why (time_proposal.reason.*); `proposalId` is the entry's pending
 *   proposal for `already_proposed`, else null
 *
 * `changed` = a proposal was written: the controller tells the judges' pages (after the commit).
 */
readonly final class TimeProposalAnswer
{
    public const string PROPOSED = 'proposed';
    public const string WITHDRAWN = 'withdrawn';
    public const string KNOWN = 'known';
    public const string REFUSED = 'refused';

    private function __construct(
        public string $status,
        public null|string $reason,
        public null|string $proposalId,
        public string $roundId,
    ) {
    }

    public static function proposed(string $proposalId, string $roundId): self
    {
        return new self(self::PROPOSED, null, $proposalId, $roundId);
    }

    public static function withdrawn(string $proposalId, string $roundId): self
    {
        return new self(self::WITHDRAWN, null, $proposalId, $roundId);
    }

    public static function known(string $proposalId, string $roundId): self
    {
        return new self(self::KNOWN, null, $proposalId, $roundId);
    }

    public static function refused(string $reason, string $roundId, null|string $proposalId = null): self
    {
        return new self(self::REFUSED, $reason, $proposalId, $roundId);
    }

    public function changed(): bool
    {
        return $this->status === self::PROPOSED || $this->status === self::WITHDRAWN;
    }
}
