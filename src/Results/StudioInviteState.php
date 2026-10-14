<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

use DateTimeImmutable;
use SpeedPuzzling\Web\Entity\StudioInvite;
use SpeedPuzzling\Web\Value\RoundClock;
use SpeedPuzzling\Web\Value\StudioInviteStatus;

/**
 * An invitation to the studio as the organiser's endpoints answer it (`online_studio_invite`, `online_studio_end`):
 * its status as of now (an unanswered one runs out - StudioInvite::statusAt()), epoch milliseconds for every moment.
 */
readonly final class StudioInviteState
{
    public function __construct(
        public string $id,
        public string $participantId,
        public StudioInviteStatus $status,
        public int $invitedAtMs,
        public int $expiresAtMs,
        public null|int $onAirFromMs,
        public null|int $onAirToMs,
    ) {
    }

    public static function of(StudioInvite $invite, DateTimeImmutable $now): self
    {
        return new self(
            id: $invite->id->toString(),
            participantId: $invite->participant->id->toString(),
            status: $invite->statusAt($now),
            invitedAtMs: RoundClock::milliseconds($invite->invitedAt),
            expiresAtMs: RoundClock::milliseconds($invite->expiresAt()),
            onAirFromMs: $invite->onAirFrom === null ? null : RoundClock::milliseconds($invite->onAirFrom),
            onAirToMs: $invite->onAirTo === null ? null : RoundClock::milliseconds($invite->onAirTo),
        );
    }

    /**
     * @return array{id: string, participantId: string, status: string, invitedAtMs: int, expiresAtMs: int, onAirFromMs: null|int, onAirToMs: null|int}
     */
    public function toArray(): array
    {
        return [
            'id' => $this->id,
            'participantId' => $this->participantId,
            'status' => $this->status->value,
            'invitedAtMs' => $this->invitedAtMs,
            'expiresAtMs' => $this->expiresAtMs,
            'onAirFromMs' => $this->onAirFromMs,
            'onAirToMs' => $this->onAirToMs,
        ];
    }
}
