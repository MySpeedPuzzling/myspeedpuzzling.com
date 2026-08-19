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
            // Password reset is the same shape of hazard as the sign-in link: an
            // unauthenticated endpoint that mails an address the caller picks.
            'password_reset_email' => [
                'policy' => 'sliding_window',
                'limit' => 3,
                'interval' => '15 minutes',
            ],
            'password_reset_ip' => [
                'policy' => 'sliding_window',
                'limit' => 20,
                'interval' => '1 hour',
            ],
            // Registration creates rows and sends mail; generous enough for a
            // household or a club behind one NAT, tight enough to be no fun to abuse
            // (D8 - the unique-email form error is only acceptable when the endpoint
            // cannot be enumerated in bulk).
            'registration_ip' => [
                'policy' => 'sliding_window',
                'limit' => 10,
                'interval' => '1 hour',
            ],
            // "Resend the verification email" - authenticated, so the account is the key
            'email_verification_resend' => [
                'policy' => 'sliding_window',
                'limit' => 3,
                'interval' => '15 minutes',
            ],
            // "E-mail me the account deletion link" - authenticated, so the account is
            // the key. Enough for "it did not arrive, send another", no more.
            'account_deletion_request' => [
                'policy' => 'sliding_window',
                'limit' => 3,
                'interval' => '15 minutes',
            ],
            // The public newsletter signup form mails an address the caller picks
            // (double opt-in confirmation) - same hazard class as the sign-in link.
            // Per address: enough for "it did not arrive, send another", no more.
            'newsletter_subscribe_email' => [
                'policy' => 'sliding_window',
                'limit' => 3,
                'interval' => '15 minutes',
            ],
            'newsletter_subscribe_ip' => [
                'policy' => 'sliding_window',
                'limit' => 20,
                'interval' => '1 hour',
            ],
            // Public API catalog search (GET /api/v1/puzzles) - the only unbounded
            // read in V1, so scraping is kept to a walk. Keyed by the token owner
            // (player behind a PAT / auth-code token, client id for client_credentials);
            // rejection is a 429 with Retry-After. Documented on /for-developers and
            // in docs/features/api/README.md - keep the three in sync.
            'api_puzzle_search' => [
                'policy' => 'sliding_window',
                'limit' => 60,
                'interval' => '1 minute',
            ],
        ],
    ],
]);
