<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

enum TrickleVerificationResult
{
    case Verified;
    case InvalidCredentials;

    // Auth0 refused the login because the password appears in a breach database -
    // the user must reset their password, a retry with the same password cannot work
    case PasswordLeaked;

    // Auth0 could not give a verdict (network error, 5xx, rate limiting) - the
    // attempt must fail without treating the password as wrong
    case Unavailable;
}
