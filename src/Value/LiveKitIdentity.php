<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * Who somebody is in the LiveKit rooms of an online round (docs/features/online-competitions/contracts/phase-1b.md §3)
 * - the identity in their access token, the one LiveKit shows every other client and names in its webhooks:
 * - `p:{participantId}` the participant's camera device (the device holding their entry);
 * - `p:{participantId}:screen` another device of theirs, watching only;
 * - `m:{playerId}` a moderator or host (ONLINE_ROUNDS_MANAGE);
 * - `s:{random}` a spectator of the studio.
 * Ids in lower case. assets/online/livekit_session.js parses them the same way (parseIdentity()).
 */
final readonly class LiveKitIdentity
{
    public const string PARTICIPANT_PREFIX = 'p:';
    public const string SCREEN_SUFFIX = ':screen';
    public const string MODERATOR_PREFIX = 'm:';
    public const string SPECTATOR_PREFIX = 's:';

    public static function participant(string $participantId): string
    {
        return self::PARTICIPANT_PREFIX . strtolower($participantId);
    }

    public static function participantScreen(string $participantId): string
    {
        return self::participant($participantId) . self::SCREEN_SUFFIX;
    }

    public static function moderator(string $playerId): string
    {
        return self::MODERATOR_PREFIX . strtolower($playerId);
    }

    public static function spectator(string $random): string
    {
        return self::SPECTATOR_PREFIX . $random;
    }

    /**
     * The participant whose camera device this identity is - null for a second screen, a moderator, a spectator or
     * anything else
     */
    public static function participantOf(string $identity): null|string
    {
        if (!str_starts_with($identity, self::PARTICIPANT_PREFIX)) {
            return null;
        }

        $participantId = substr($identity, strlen(self::PARTICIPANT_PREFIX));

        return CanonicalUuid::isValid($participantId) ? strtolower($participantId) : null;
    }
}
