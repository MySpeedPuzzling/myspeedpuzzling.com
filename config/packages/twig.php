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
            // Social login (auth hardening PR 2). While admin_only is ON the
            // login/register buttons render for NOBODY - those pages are
            // anonymously cached and must stay uniform (#164).
            'social_login_admin_only' => '%socialLoginAdminOnly%',
            'social_login_google_enabled' => '%socialLoginGoogleEnabled%',
            'social_login_facebook_enabled' => '%socialLoginFacebookEnabled%',
            'social_login_apple_enabled' => '%socialLoginAppleEnabled%',
            // Pairs & teams picker rollout - see docs/features/feature_flags.md
            'pairs_teams_picker_public' => '%pairsTeamsPickerPublic%',
        ],
        'paths' => [
            '%kernel.project_dir%/public/img' => 'images',
            '%kernel.project_dir%/public/css' => 'styles',
        ],
    ],
]);
