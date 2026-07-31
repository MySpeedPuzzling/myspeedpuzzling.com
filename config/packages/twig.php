<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use SpeedPuzzling\Web\Query\GetConversations;
use SpeedPuzzling\Web\Query\GetNotifications;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;

return App::config([
    'twig' => [
        'form_themes' => ['bootstrap_5_layout.html.twig'],
        'date' => [
            'timezone' => 'Europe/Prague',
        ],
        'globals' => [
            'ga_tracking' => '%env(GA_TRACKING)%',
            'logged_user' => '@' . RetrieveLoggedUserProfile::class,
            'get_notifications' => '@' . GetNotifications::class,
            'get_conversations' => '@' . GetConversations::class,
            'mercure_public_url' => '%env(MERCURE_PUBLIC_URL)%',
            'images_base_url' => '%env(NGINX_PROXY_BASE_URL)%',
            // Site-wide advance notice for the Auth0 -> native sign-in migration
            // (issue #147, docs/features/feature_flags.md). ON by default: it is
            // announcement copy, not a feature - the switch exists to retire it.
            'sign_in_changes_notice_enabled' => '%env(bool:SIGN_IN_CHANGES_NOTICE_ENABLED)%',
            // Stage A/B flags of the same migration. Templates need them to decide
            // whether to offer the native "Create an account" CTA (Stage A) and the
            // native change-password card (Stage B); both retire in Phase 6.
            'native_registration_enabled' => '%nativeRegistrationEnabled%',
            'native_login_enabled' => '%nativeLoginEnabled%',
            // Transition-window escape hatch: the "old Auth0 sign-in" link on the
            // native login page. Retires in Phase 6.
            'auth0_fallback_login_enabled' => '%auth0FallbackLoginEnabled%',
            // Social login (auth hardening PR 2). While admin_only is ON the
            // login/register buttons render for NOBODY - those pages are
            // anonymously cached and must stay uniform (#164).
            'social_login_admin_only' => '%socialLoginAdminOnly%',
            'social_login_google_enabled' => '%socialLoginGoogleEnabled%',
            'social_login_facebook_enabled' => '%socialLoginFacebookEnabled%',
            'social_login_apple_enabled' => '%socialLoginAppleEnabled%',
        ],
        'paths' => [
            '%kernel.project_dir%/public/img' => 'images',
            '%kernel.project_dir%/public/css' => 'styles',
        ],
    ],
]);
