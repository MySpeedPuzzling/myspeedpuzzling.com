<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\LiveKit;

/**
 * The open invitation to the studio of a participant in a round, where their phone's heartbeat finds it without
 * Postgres (contract phase-1b.md §4: "a missed data message costs nothing") - the heartbeat stays statement-free
 * (ApiHeartbeatController). Raised once InviteToStudio is committed, for as long as the invitation waits for its answer
 * (StudioInvite::EXPIRES_AFTER_SECONDS); cleared on the answer and on the host's end. A hint only: the invitation's row
 * is the truth (the state endpoint reads it).
 *
 * Fails open: a store that is down never fails a request - the signal is then missing (logged), the data message and
 * the state still carry the invitation.
 */
interface StudioInviteSignal
{
    public function raise(string $roundId, string $participantId, string $inviteId, int $ttlSeconds): void;

    /**
     * The open invitation's id - null when there is none (or the store is down)
     */
    public function open(string $roundId, string $participantId): null|string;

    /**
     * Cleared only while it still names this invitation - a newer one stays
     */
    public function clear(string $roundId, string $participantId, string $inviteId): void;
}
