<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

// The limiter cache is not rolled back between tests or runs (DAMA only wraps
// the database), so the shared per-IP budget would trip on rapid successive
// local runs - every BrowserKit request comes from the same client IP. The
// per-email limiter keeps production limits; tests use randomized emails.
return App::config([
    'framework' => [
        'rate_limiter' => [
            'login_ip' => [
                'policy' => 'sliding_window',
                'limit' => 100000,
                'interval' => '1 minute',
            ],
            'sign_in_link_ip' => [
                'policy' => 'sliding_window',
                'limit' => 100000,
                'interval' => '1 hour',
            ],
            // Fixture players upload in many tests and runs - the budget would trip within an hour of local runs
            'page_section_image_upload' => [
                'policy' => 'sliding_window',
                'limit' => 100000,
                'interval' => '1 hour',
            ],
            // The online competitions' participant API: every test comes from one IP and uses the fixture's links - the
            // limits would trip within a minute of local runs. Tests of a 429 read the limit and use up a key of their own.
            'online_participant_ip' => [
                'policy' => 'sliding_window',
                'limit' => 100000,
                'interval' => '1 minute',
                'lock_factory' => null,
                'cache_pool' => 'online_participant_rate_limits',
            ],
            'online_participant' => [
                'policy' => 'sliding_window',
                'limit' => 100000,
                'interval' => '1 minute',
                'lock_factory' => null,
                'cache_pool' => 'online_participant_rate_limits',
            ],
            'online_participant_events' => [
                'policy' => 'sliding_window',
                'limit' => 100000,
                'interval' => '1 minute',
                'lock_factory' => null,
                'cache_pool' => 'online_participant_rate_limits',
            ],
            'online_participant_media' => [
                'policy' => 'sliding_window',
                'limit' => 100000,
                'interval' => '1 minute',
                'lock_factory' => null,
                'cache_pool' => 'online_participant_rate_limits',
            ],
        ],
    ],
]);
