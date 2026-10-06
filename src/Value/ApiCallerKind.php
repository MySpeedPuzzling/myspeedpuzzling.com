<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The three kinds of public API callers (docs/features/api/usage-statistics.md).
 * The value is the prefix of ApiCaller::key().
 */
enum ApiCallerKind: string
{
    // A personal access token - always a player's own
    case PersonalAccessToken = 'pat';
    // An OAuth2 authorization-code token: an app acting for the player who connected it
    case OAuth2User = 'oauth';
    // An OAuth2 client_credentials token: the app itself, no player behind it
    case OAuth2Client = 'client';
}
