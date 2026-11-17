<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * One time proposal as the judges' tools read it (GetTimeProposals) - the round state's `proposals` and the
 * `official_results.proposals` Mercure message (toolkit/B-qr-competitor-page.md §6.1 with R-review-and-plan.md §1.2;
 * no puzzle choice in v1). The same payload goes to the organisers' and the referees' topic: who proposed is the
 * participant's organiser-recorded name only - never the linked player's id, #code or profile name.
 *
 * - `late` = more than 5 s between the tap and the receipt: the judge shows both seconds and the lateness, and the
 *   card's default value is the receipt time (R §2.4).
 * - `proposedBy.linkedAt` = when the proposer's profile was linked to that name, only when that was less than a day
 *   before the tap (R §2.4: "linked to this name on {date}"); null otherwise.
 * - `decided` once it is not pending.
 *
 * forCompetitor() is what the competitor's own phone gets: the judge's identity and the link moment left out.
 */
readonly final class TimeProposalView implements \JsonSerializable
{
    public const int RECENT_LINK_MS = 86_400_000;

    public function __construct(
        public string $id,
        public string $roundId,
        // RoundEntryRef string
        public string $entry,
        public null|int $tableNumber,
        public string $status,
        public int $proposedSeconds,
        public int $receiptSeconds,
        public int $tappedAtMs,
        public int $receivedAtMs,
        public int $lateByMs,
        public bool $late,
        public bool $clockChanged,
        public string $proposedByParticipantId,
        public string $proposedByName,
        public null|DateTimeImmutable $proposerLinkedAt,
        public null|DateTimeImmutable $decidedAt,
        public null|string $decidedById,
        public null|string $decidedByName,
        public null|int $decidedSeconds,
        public null|string $decisionReason,
        public bool $forCompetitor = false,
    ) {
    }

    public function forCompetitor(): self
    {
        return new self(
            id: $this->id,
            roundId: $this->roundId,
            entry: $this->entry,
            tableNumber: $this->tableNumber,
            status: $this->status,
            proposedSeconds: $this->proposedSeconds,
            receiptSeconds: $this->receiptSeconds,
            tappedAtMs: $this->tappedAtMs,
            receivedAtMs: $this->receivedAtMs,
            lateByMs: $this->lateByMs,
            late: $this->late,
            clockChanged: $this->clockChanged,
            proposedByParticipantId: $this->proposedByParticipantId,
            proposedByName: $this->proposedByName,
            proposerLinkedAt: null,
            decidedAt: $this->decidedAt,
            decidedById: null,
            decidedByName: null,
            decidedSeconds: $this->decidedSeconds,
            decisionReason: $this->decisionReason,
            forCompetitor: true,
        );
    }

    public function isPending(): bool
    {
        return $this->status === 'pending';
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        $recentLink = $this->proposerLinkedAt !== null
            && $this->tappedAtMs - (int) $this->proposerLinkedAt->format('Uv') < self::RECENT_LINK_MS;

        $decided = null;

        if ($this->decidedAt !== null) {
            $decided = [
                'at' => $this->decidedAt->format(DateTimeImmutable::ATOM),
                ...($this->forCompetitor ? [] : ['byPlayerId' => $this->decidedById, 'byName' => $this->decidedByName]),
                'seconds' => $this->decidedSeconds,
                'reason' => $this->decisionReason,
            ];
        }

        return [
            'id' => $this->id,
            'roundId' => $this->roundId,
            'entry' => $this->entry,
            'tableNumber' => $this->tableNumber,
            'status' => $this->status,
            'proposedSeconds' => $this->proposedSeconds,
            'receiptSeconds' => $this->receiptSeconds,
            'tappedAtMs' => $this->tappedAtMs,
            'receivedAtMs' => $this->receivedAtMs,
            'lateByMs' => $this->lateByMs,
            'late' => $this->late,
            'clockChanged' => $this->clockChanged,
            'proposedBy' => [
                'participantId' => $this->proposedByParticipantId,
                'name' => $this->proposedByName,
                ...($this->forCompetitor ? [] : ['linkedAt' => $recentLink ? $this->proposerLinkedAt->format(DateTimeImmutable::ATOM) : null]),
            ],
            'decided' => $decided,
        ];
    }
}
