<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The two LiveKit rooms of an online round (docs/features/online-competitions/contracts/phase-1b.md §3): the hall
 * `round-{roundId}` - the participants' puzzle cameras, the moderators, the host's voice - and the studio
 * `studio-{roundId}` - the host and the finishers they bring on air, watched by spectators.
 */
enum LiveKitRoomKind: string
{
    case Hall = 'hall';
    case Studio = 'studio';

    public function roomName(string $roundId): string
    {
        return $this->prefix() . strtolower($roundId);
    }

    /**
     * The round of a room name of this kind - null for any other name
     */
    public function roundOf(string $roomName): null|string
    {
        if (!str_starts_with($roomName, $this->prefix())) {
            return null;
        }

        $roundId = substr($roomName, strlen($this->prefix()));

        return CanonicalUuid::isValid($roundId) ? strtolower($roundId) : null;
    }

    private function prefix(): string
    {
        return match ($this) {
            self::Hall => 'round-',
            self::Studio => 'studio-',
        };
    }
}
