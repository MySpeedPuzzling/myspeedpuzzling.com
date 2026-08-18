<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What an OAuth callback is supposed to do, carried in the server-side state
 * payload: `Login` runs the account-resolution rules 1-4 (per-provider
 * authenticators), `Link` attaches the identity to an already-proven account
 * (rule 5, handled by the callback controller because Apple's cross-site POST
 * arrives without session cookies).
 */
enum OauthFlowIntent: string
{
    case Login = 'login';
    case Link = 'link';
}
