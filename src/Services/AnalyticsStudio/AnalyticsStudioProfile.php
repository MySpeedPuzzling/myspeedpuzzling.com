<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\AnalyticsStudio;

/**
 * A public player's profile as the analytics studio's JSON carries it: the English path of `player_profile`
 * (`/en/player-profile/{playerId}`) - the answers are the same for everybody and cached, so they carry no locale; the
 * page may swap the prefix for its own. Only ever called for a public (analysable) player.
 */
final class AnalyticsStudioProfile
{
    public static function path(string $playerId): string
    {
        return '/en/player-profile/' . strtolower($playerId);
    }
}
