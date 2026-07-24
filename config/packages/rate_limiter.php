<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

// Native login brute-force protection, consumed inside LoginFormAuthenticator.
// Deliberately NOT the firewall-level `login_throttling` feature: its listener
// consumes a token on every LoginFailureEvent of the firewall, and during the
// migration window the Auth0 authenticator fails on every anonymous request -
// anonymous browsing would eat the per-IP budget and lock out real logins.
// Revisit once the Auth0 authenticator leaves the firewall (Phase 6).
return App::config([
    'framework' => [
        'rate_limiter' => [
            // Brute force on a single account (keyed email + client IP)
            'login_email_ip' => [
                'policy' => 'sliding_window',
                'limit' => 5,
                'interval' => '1 minute',
            ],
            // Password spraying across many accounts from one IP. Generous limit:
            // competition venues put many players behind one NAT
            'login_ip' => [
                'policy' => 'sliding_window',
                'limit' => 100,
                'interval' => '1 minute',
            ],
            // "Email me a sign-in link" is an unauthenticated endpoint that sends mail
            // to an address the caller picks - it must never become a mail cannon.
            // Per address: enough for "it did not arrive, send another", no more.
            'sign_in_link_email' => [
                'policy' => 'sliding_window',
                'limit' => 3,
                'interval' => '15 minutes',
            ],
            'sign_in_link_ip' => [
                'policy' => 'sliding_window',
                'limit' => 20,
                'interval' => '1 hour',
            ],
        ],
    ],
]);
