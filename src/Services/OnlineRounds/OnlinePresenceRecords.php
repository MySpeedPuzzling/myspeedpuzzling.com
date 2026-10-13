<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

/**
 * The presence as OnlinePresence::forRound() answers it, from the beats of the devices holding the entries and the
 * beats kept apart (another device on the same link) - the one rule both implementations share.
 */
final readonly class OnlinePresenceRecords
{
    /**
     * @param array<string, array<string, mixed>> $holders participant id => the holding device's last beat
     * @param array<string, array<string, mixed>> $others participant id => another device's last beat
     * @return array<string, array<string, mixed>>
     */
    public static function of(array $holders, array $others): array
    {
        $presence = $holders;

        foreach ($others as $participantId => $beat) {
            $deviceId = is_string($beat['deviceId'] ?? null) ? $beat['deviceId'] : null;

            // A device that holds the entry again (it took the link back) is no other device
            if ($deviceId !== null && $deviceId === ($holders[$participantId]['deviceId'] ?? null)) {
                continue;
            }

            $presence[$participantId] = [
                ...($presence[$participantId] ?? []),
                'otherDevice' => [
                    'deviceId' => $deviceId,
                    'lastSeenMs' => is_int($beat['lastSeenMs'] ?? null) ? $beat['lastSeenMs'] : null,
                ],
            ];
        }

        return $presence;
    }
}
