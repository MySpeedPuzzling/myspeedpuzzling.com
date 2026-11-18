<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;

/**
 * One time proposal as the judges' tools read it (GetTimeProposals) - the round state's `proposals` and the
 * `official_results.proposals` Mercure message (toolkit/B-qr-competitor-page.md §6.1 with R-review-and-plan.md §1.2;
 * the puzzle choice since v2 C2). The same payload goes to the organisers' and the referees' topic: who proposed is the
 * participant's organiser-recorded name only - never the linked player's id, #code or profile name.
 *
 * - `late` = more than 5 s between the tap and the receipt - data kept for compatibility, no judge UI: the recorded
 *   time is always the tap's (Jan). `timing` (reliability hardening T2, TimeProposalTiming): `{verdict:
 *   on_time|offline|suspicious, lastHeardAtMs, clockUncertain, queuedOffline, attempts}` - `suspicious` /
 *   `clockUncertain` make the card warn. Null on a proposal from before T2. Judges only - never on the competitor's phone.
 * - `proposedBy.linkedAt` = when the proposer's profile was linked to that name, only when that was less than a day
 *   before the tap (R §2.4: "linked to this name on {date}"); null otherwise.
 * - `decided` once it is not pending.
 * - `puzzles` (competition toolkit v2 C2) only when the competitor chose puzzles of a `pick` round: the judges get
 *   `["<roundPuzzleId>", …]` in solving order (the card pre-selects them, the judge confirms), the competitor
 *   `[{position, label}, …]` - what their phone shows. A puzzle removed from the round since is left out.
 *
 * forCompetitor() is what the competitor's own phone gets: the judge's identity and the link moment left out.
 */
readonly final class TimeProposalView implements \JsonSerializable
{
    public const int RECENT_LINK_MS = 86_400_000;

    /**
     * @param null|non-empty-list<array{id: string, position: int, label: string}> $puzzles
     */
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
        public null|string $timingVerdict,
        public null|int $lastHeardAtMs,
        public bool $clockUncertain,
        public bool $queuedOffline,
        public null|int $sendAttempts,
        public string $proposedByParticipantId,
        public string $proposedByName,
        public null|DateTimeImmutable $proposerLinkedAt,
        public null|DateTimeImmutable $decidedAt,
        public null|string $decidedById,
        public null|string $decidedByName,
        public null|int $decidedSeconds,
        public null|string $decisionReason,
        public bool $forCompetitor = false,
        // v2 C2: the competitor's puzzle choice as places of the round now (GetTimeProposals) - null = none
        public null|array $puzzles = null,
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
            timingVerdict: $this->timingVerdict,
            lastHeardAtMs: $this->lastHeardAtMs,
            clockUncertain: $this->clockUncertain,
            queuedOffline: $this->queuedOffline,
            sendAttempts: $this->sendAttempts,
            proposedByParticipantId: $this->proposedByParticipantId,
            proposedByName: $this->proposedByName,
            proposerLinkedAt: null,
            decidedAt: $this->decidedAt,
            decidedById: null,
            decidedByName: null,
            decidedSeconds: $this->decidedSeconds,
            decisionReason: $this->decisionReason,
            forCompetitor: true,
            puzzles: $this->puzzles,
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

        $json = [
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
            ...($this->forCompetitor ? [] : ['timing' => $this->timingVerdict === null ? null : [
                'verdict' => $this->timingVerdict,
                'lastHeardAtMs' => $this->lastHeardAtMs,
                'clockUncertain' => $this->clockUncertain,
                'queuedOffline' => $this->queuedOffline,
                'attempts' => $this->sendAttempts,
            ]]),
            'proposedBy' => [
                'participantId' => $this->proposedByParticipantId,
                'name' => $this->proposedByName,
                ...($this->forCompetitor ? [] : ['linkedAt' => $recentLink ? $this->proposerLinkedAt->format(DateTimeImmutable::ATOM) : null]),
            ],
            'decided' => $decided,
        ];

        // v2 C2: only with a choice - proposals without one keep their JSON. The judges get the round puzzle ids to
        // pre-select, the competitor's phone the places it shows (never an id: nothing of a hidden puzzle but its label)
        if ($this->puzzles !== null) {
            $json['puzzles'] = $this->forCompetitor
                ? array_map(static fn (array $place): array => ['position' => $place['position'], 'label' => $place['label']], $this->puzzles)
                : array_map(static fn (array $place): string => $place['id'], $this->puzzles);
        }

        return $json;
    }
}
