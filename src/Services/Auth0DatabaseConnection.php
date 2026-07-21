<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services;

final class Auth0DatabaseConnection
{
    /**
     * Auth0 encodes the originating connection into the user id prefix. Only
     * database (username/password) identities carry a password that can be
     * changed - social and passwordless ones (`google-oauth2|`, `facebook|`,
     * `apple|`, `email|`, `sms|`, ...) authenticate elsewhere, so offering them
     * a password reset would send an email that can never be acted upon.
     */
    private const string DATABASE_USER_ID_PREFIX = 'auth0|';

    public static function hasPassword(string $userId): bool
    {
        return str_starts_with($userId, self::DATABASE_USER_ID_PREFIX);
    }
}
