<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Security;

/**
 * The one-time hand-off between a magic-link login and the password prompt that
 * follows it. The flag lives in the session and is consumed by the prompt
 * controller: setting a password without proving the current one is only ever
 * allowed in the few moments after the user proved control of their mailbox.
 */
final class SignInLinkPasswordPrompt
{
    public const string SESSION_KEY = 'native_auth:set_password_prompt';
}
