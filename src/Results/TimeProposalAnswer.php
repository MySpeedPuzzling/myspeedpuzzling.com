<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * What ProposeRoundTimeHandler / WithdrawTimeProposalHandler / ChangeTimeProposalPuzzlesHandler did (read it from the
 * envelope's HandledStamp):
 * - proposed: a new pending proposal was stored
 * - withdrawn: the pending proposal is withdrawn now
 * - chosen: the pending proposal's puzzle choice is changed (or cleared) now (competition toolkit v2 C2)
 * - known: nothing changed - a resend of a proposal stored before, or a withdrawal / choice of one no longer pending
 * - refused: nothing changed, `reason` says why (time_proposal.reason.*); `proposalId` is the entry's pending
 *   proposal for `already_proposed`, else null
 *
 * `puzzlesRefused` (v2 C2): the proposal was stored but the puzzle choice sent with it was not (time_proposal.reason.*)
 * - the tap never fails because of its choice. `changed` = a proposal was written: the controller tells the judges'
 * pages (after the commit).
 */
readonly final class TimeProposalAnswer
{
    public const string PROPOSED = 'proposed';
    public const string WITHDRAWN = 'withdrawn';
    public const string CHOSEN = 'chosen';
    public const string KNOWN = 'known';
    public const string REFUSED = 'refused';

    private function __construct(
        public string $status,
        public null|string $reason,
        public null|string $proposalId,
        public string $roundId,
        public null|string $puzzlesRefused = null,
    ) {
    }

    public static function proposed(string $proposalId, string $roundId, null|string $puzzlesRefused = null): self
    {
        return new self(self::PROPOSED, null, $proposalId, $roundId, $puzzlesRefused);
    }

    public static function withdrawn(string $proposalId, string $roundId): self
    {
        return new self(self::WITHDRAWN, null, $proposalId, $roundId);
    }

    public static function chosen(string $proposalId, string $roundId): self
    {
        return new self(self::CHOSEN, null, $proposalId, $roundId);
    }

    public static function known(string $proposalId, string $roundId): self
    {
        return new self(self::KNOWN, null, $proposalId, $roundId);
    }

    public static function refused(string $reason, string $roundId, null|string $proposalId = null): self
    {
        return new self(self::REFUSED, $reason, $proposalId, $roundId);
    }

    /**
     * The same answer, saying the puzzle choice sent along was not taken (only a stored proposal says so)
     */
    public function withPuzzlesRefused(string $reason): self
    {
        return $this->status === self::PROPOSED
            ? new self($this->status, $this->reason, $this->proposalId, $this->roundId, $reason)
            : $this;
    }

    public function changed(): bool
    {
        return $this->status === self::PROPOSED || $this->status === self::WITHDRAWN || $this->status === self::CHOSEN;
    }
}
