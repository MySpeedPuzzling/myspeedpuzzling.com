<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

/**
 * Who is there in an online round right now (docs/features/online-competitions/README.md §5.6 "Presence"): the last
 * heartbeat of every participant's phone - device, camera, detector, clock quality, visibility. Telemetry, never
 * application state: it lives in redis-state, never in Postgres, and expires on its own.
 *
 * The participant's presence is the device holding their entry (the last one that said "I'm ready" - any device while
 * none holds it). A beat of another device (a second phone or a laptop opened on the same link, one that has not
 * agreed to the photos) is kept apart and never overwrites it: the control room shows the holder's camera and
 * connection, and may say that another device is open.
 */
interface OnlinePresence
{
    /**
     * @param array<string, mixed> $data the heartbeat as the phone sent it (deviceId, visible, camera, detector, clock, outbox)
     * @param bool $holdsEntry the beating device holds the entry (or nobody does) - false: another device, kept apart
     */
    public function beat(string $roundId, string $participantId, array $data, int $nowMs, bool $holdsEntry = true): void;

    /**
     * @return array<string, array<string, mixed>> lower-case participant id => the last heartbeat of the device holding
     *                                             the entry, with `lastSeenMs`; plus `otherDevice` = another device's
     *                                             last beat ({deviceId, lastSeenMs}) when one beat since - alone when
     *                                             only another device beat
     */
    public function forRound(string $roundId): array;
}
