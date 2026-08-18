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
        ],
    ],
]);
