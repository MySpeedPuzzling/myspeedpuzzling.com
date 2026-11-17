<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\RoundEntryRef;
use SpeedPuzzling\Web\Value\TimeProposalDecisionReason;
use SpeedPuzzling\Web\Value\TimeProposalStatus;
use SpeedPuzzling\Web\Value\TimeProposalVerdict;

/**
 * A competitor's "propose my time" (competition toolkit P1 - toolkit/B-qr-competitor-page.md §5.2-5.3 with
 * R-review-and-plan.md §1.2, §2.4): the moment a linked member of a round entry tapped Stop on their own competitor
 * page, timed against the round's stopwatch. Never official on its own - a judge confirms it with an ordinary result
 * change carrying its id (RecordRoundResults, TimeProposalResolver), or rejects it with a `field: "proposal"` change.
 *
 * - The id is the phone's UUIDv7, made at the tap: a resend is answered, never stored twice (ProposeRoundTimeHandler).
 * - Exactly one of the two entries (a solo entry or a pair/team) - checked here, no CHECK constraint (house style).
 * - One pending proposal per entry: checked under the event's participants lock, backed by the partial unique indexes
 *   `custom_time_proposal_pending_solo` / `custom_time_proposal_pending_team` (migration + tests/bootstrap.php).
 * - The times: `tappedAtMs` is the phone's server-synced clock at the tap, `receivedAtMs` the server's receipt; the
 *   seconds of both against the stopwatch start the server used (`startedAtMs`). Late (> 5 s, isLate()) is shown to the
 *   judge with both times. Since reliability hardening T2 the judge's default follows `timingVerdict`
 *   (TimeProposalTiming - evidence from the competitor page's heartbeats): only `suspicious` defaults to the receipt;
 *   a row from before T2 (no verdict) keeps the old rule (late → the receipt).
 * - Gone with the round, the entry and the participant (ON DELETE CASCADE - DeleteCompetitionHandler deletes in SQL).
 */
#[Entity]
#[Index(columns: ['round_id', 'status'])]
class CompetitionTimeProposal
{
    // Up to this much between the tap and the receipt is a normal mobile network and the outbox's first retry
    public const int LATE_AFTER_MS = 5000;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 16, enumType: TimeProposalStatus::class)]
    public TimeProposalStatus $status = TimeProposalStatus::Pending;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $decidedAt = null;

    // The judge or organiser who decided (null: decided by the system - a stopwatch reset)
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[ManyToOne]
    #[JoinColumn(onDelete: 'SET NULL')]
    public null|Player $decidedByPlayer = null;

    // The official seconds written when it was confirmed - may differ from proposedSeconds (the judge's verdict)
    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(nullable: true)]
    public null|int $decidedSeconds = null;

    #[Immutable(Immutable::PRIVATE_WRITE_SCOPE)]
    #[Column(length: 32, nullable: true, enumType: TimeProposalDecisionReason::class)]
    public null|TimeProposalDecisionReason $decisionReason = null;

    public function __construct(
        // The phone's UUIDv7
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public CompetitionRound $round,
        // A solo entry - or
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(onDelete: 'CASCADE')]
        public null|CompetitionParticipantRound $participantRound,
        // a pair/team entry
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(onDelete: 'CASCADE')]
        public null|CompetitionTeam $team,
        // Who tapped - the participant (organiser-recorded name, safe on the referees' topic), not the player
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public CompetitionParticipant $proposedByParticipant,
        // Audit: the signed-in player the participant was linked to at the tap
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(onDelete: 'SET NULL')]
        public null|Player $proposedByPlayer,
        // When that player was linked to the participant (a link younger than a day is shown to the judge)
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
        public null|DateTimeImmutable $proposerLinkedAt,
        #[Immutable]
        #[Column(type: Types::BIGINT)]
        public int $tappedAtMs,
        #[Immutable]
        #[Column(type: Types::BIGINT)]
        public int $receivedAtMs,
        // What the phone's clock used - diagnostics, like OnlineEntryEvent
        #[Immutable]
        #[Column(type: Types::FLOAT, nullable: true)]
        public null|float $clockOffsetMs,
        #[Immutable]
        #[Column(type: Types::FLOAT, nullable: true)]
        public null|float $clockRttMs,
        // RoundClock::stateHash() the phone timed against
        #[Immutable]
        #[Column(length: 32, nullable: true)]
        public null|string $stopwatchStateHash,
        // The stopwatch start the server used for the seconds
        #[Immutable]
        #[Column(type: Types::BIGINT)]
        public int $startedAtMs,
        #[Immutable]
        #[Column]
        public int $proposedSeconds,
        // What a receipt-time rule would say
        #[Immutable]
        #[Column]
        public int $receiptSeconds,
        #[Immutable]
        #[Column]
        public int $lateByMs,
        // The phone's state hash differs from the stored one at the receipt (a pause/resume or reset since)
        #[Immutable]
        #[Column]
        public bool $clockChanged,
        // T2: how the tap reached us (TimeProposalTiming::judge()) - null on rows from before T2
        #[Immutable]
        #[Column(length: 16, nullable: true, enumType: TimeProposalVerdict::class)]
        public null|TimeProposalVerdict $timingVerdict = null,
        // T2: the heartbeat receipt the verdict rests on (the clean one of a suspicious tap, else the last one heard)
        #[Immutable]
        #[Column(type: Types::BIGINT, nullable: true)]
        public null|int $lastHeardAtMs = null,
        // T2: the phone's clock is in doubt (no sample, an old one, an offset jump, a send our clock disagrees with)
        #[Immutable]
        #[Column(options: ['default' => false])]
        public bool $clockUncertain = false,
        // T2 diagnostics, the phone's word: the age of its clock sample at the tap, whether the tap waited offline or a
        // send failed before, and which attempt reached us
        #[Immutable]
        #[Column(nullable: true)]
        public null|int $offsetSampleAgeMs = null,
        #[Immutable]
        #[Column(options: ['default' => false])]
        public bool $queuedOffline = false,
        #[Immutable]
        #[Column(nullable: true)]
        public null|int $sendAttempts = null,
    ) {
        if (($participantRound === null) === ($team === null)) {
            throw new \InvalidArgumentException('A time proposal belongs to exactly one entry - a solo entry or a pair/team.');
        }
    }

    public function entryRef(): RoundEntryRef
    {
        if ($this->participantRound !== null) {
            return $this->participantRound->entryRef();
        }

        assert($this->team !== null);

        return $this->team->entryRef();
    }

    public function isPending(): bool
    {
        return $this->status === TimeProposalStatus::Pending;
    }

    public function isLate(): bool
    {
        return $this->lateByMs > self::LATE_AFTER_MS;
    }

    /**
     * A judge recorded the official result through it - `$seconds` is what was written
     */
    public function confirm(int $seconds, Player $judge, DateTimeImmutable $at): void
    {
        $this->decide(TimeProposalStatus::Confirmed, null, $judge, $at);
        $this->decidedSeconds = $seconds;
    }

    public function reject(TimeProposalDecisionReason $reason, Player $judge, DateTimeImmutable $at): void
    {
        $this->decide(TimeProposalStatus::Rejected, $reason, $judge, $at);
    }

    /**
     * A result recorded without it (by `$by`), or the round's stopwatch reset (by nobody)
     */
    public function supersede(TimeProposalDecisionReason $reason, null|Player $by, DateTimeImmutable $at): void
    {
        $this->decide(TimeProposalStatus::Superseded, $reason, $by, $at);
    }

    /**
     * A judge cleared (an Undo) or changed the result that confirmed it - the proposal no longer describes the official
     * result. `decidedSeconds` keeps what it was confirmed as; the decision moves to `$by` and `$at`.
     */
    public function supersedeConfirmation(TimeProposalDecisionReason $reason, Player $by, DateTimeImmutable $at): void
    {
        if ($this->status !== TimeProposalStatus::Confirmed) {
            throw new \LogicException('Only a confirmed time proposal can lose its confirmation.');
        }

        $this->status = TimeProposalStatus::Superseded;
        $this->decisionReason = $reason;
        $this->decidedByPlayer = $by;
        $this->decidedAt = $at;
    }

    public function withdraw(DateTimeImmutable $at): void
    {
        $this->decide(TimeProposalStatus::Withdrawn, null, null, $at);
    }

    private function decide(TimeProposalStatus $status, null|TimeProposalDecisionReason $reason, null|Player $by, DateTimeImmutable $at): void
    {
        if ($this->isPending() === false) {
            throw new \LogicException('Only a pending time proposal can be decided.');
        }

        $this->status = $status;
        $this->decisionReason = $reason;
        $this->decidedByPlayer = $by;
        $this->decidedAt = $at;
    }
}
