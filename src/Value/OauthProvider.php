<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum OauthProvider: string
{
    case Google = 'google';
    case Apple = 'apple';
    case Facebook = 'facebook';

    /**
     * Short label stored in auth_audit_log.authenticator (see AuthAuditEvent).
     */
    public function authenticatorLabel(): string
    {
        return 'social:' . $this->value;
    }

    /**
     * Brand-guideline display name ("Continue with Google", settings list, ...).
     */
    public function displayName(): string
    {
        return match ($this) {
            self::Google => 'Google',
            self::Apple => 'Apple',
            self::Facebook => 'Facebook',
        };
    }
}
