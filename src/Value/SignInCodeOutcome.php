<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What became of a typed sign-in code (VerifySignInCodeHandler). The values
 * double as the reason code in the audit log - never the code itself.
 */
enum SignInCodeOutcome: string
{
    case Accepted = 'accepted';
    case Wrong = 'wrong';
    /** The attempt cap of the request was reached (now or earlier); the link still works */
    case LockedOut = 'locked_out';
    case Expired = 'expired';
    /** The link or the code already signed in with this request */
    case Used = 'used';
    /** No such request - the address has no account, or the row is gone */
    case Unknown = 'unknown';
    /** Not six digits - never reaches the database */
    case Malformed = 'malformed';
    /** Per-address or per-IP limiter */
    case Throttled = 'throttled';
    /** Nothing pending in this browser (never asked, expired session state, CSRF) */
    case NoPendingRequest = 'no_pending_request';
}
