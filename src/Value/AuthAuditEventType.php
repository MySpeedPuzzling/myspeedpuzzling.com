<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum AuthAuditEventType: string
{
    case LoginSuccess = 'login_success';
    case LoginFailure = 'login_failure';
    case Logout = 'logout';
    case SignInLinkRequested = 'sign_in_link_requested';
    case SignInLinkUsed = 'sign_in_link_used';
    case PasswordResetRequested = 'password_reset_requested';
    case PasswordResetCompleted = 'password_reset_completed';
    case PasswordChanged = 'password_changed';
    case Registration = 'registration';
    case EmailChangeRequested = 'email_change_requested';
    case EmailVerified = 'email_verified';
    case OauthLogin = 'oauth_login';
    case OauthRegistration = 'oauth_registration';
    case OauthIdentityLinked = 'oauth_identity_linked';
    case OauthIdentityUnlinked = 'oauth_identity_unlinked';
    case Auth0FallbackLogin = 'auth0_fallback_login';
}
