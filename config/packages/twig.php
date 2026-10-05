<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use SpeedPuzzling\Web\Query\GetConversations;
use SpeedPuzzling\Web\Query\GetNotifications;
use SpeedPuzzling\Web\Services\Email\EmailHtmlToTextConverter;
use SpeedPuzzling\Web\Services\PuzzleNameSuggestions;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Services\SocialLogin\SocialLoginSettings;

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
            // Social login (auth hardening PR 2): which provider buttons render.
            // Depends on configuration only, never on the visitor, so every
            // visitor of /login and /register gets the same buttons.
            'social_login' => '@' . SocialLoginSettings::class,
            // Meta app id for the fb:app_id Open Graph tag (empty = tag omitted)
            'facebook_app_id' => '%env(trim:string:FACEBOOK_APP_ID)%',
            // Pairs & teams picker rollout - see docs/features/feature_flags.md
            'pairs_teams_picker_public' => '%pairsTeamsPickerPublic%',
            // "Suggest another name" in the puzzle page's menu - see docs/features/feature_flags.md
            'puzzle_name_suggestions' => '@' . PuzzleNameSuggestions::class,
        ],
        'paths' => [
            '%kernel.project_dir%/public/img' => 'images',
            '%kernel.project_dir%/public/css' => 'styles',
        ],
        // The plain-text part of e-mails keeps links and paragraphs (Symfony's default strip_tags() drops every URL)
        'mailer' => [
            'html_to_text_converter' => EmailHtmlToTextConverter::class,
        ],
    ],
]);
