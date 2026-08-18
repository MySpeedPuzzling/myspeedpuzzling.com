<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Exceptions;

use Exception;

/**
 * Server-side half of the SOCIAL_LOGIN_ADMIN_ONLY rollout stage: while the flag
 * is ON, only admin accounts may use, link or register via social login - the
 * UI gates alone are not enough (plan §Feature flags + admin-only rollout).
 */
final class SocialLoginRestrictedToAdmins extends Exception
{
    public function __construct()
    {
        parent::__construct('Social login is restricted to admin accounts during the rollout stage');
    }
}
