<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

// Login brute-force protection, consumed inside LoginFormAuthenticator.
// Deliberately NOT the firewall-level `login_throttling` feature, whose listener
// consumes a token on every LoginFailureEvent of the firewall (an expired sign-in
// link or a rejected remember-me cookie included). Chosen while the Auth0
// authenticator failed on every anonymous request, kept after it left
// (2026-09-18): these limiters count password attempts and nothing else.
return App::config([
    'framework' => [
        // The lock of the studio's spectator count (StudioSpectators): on the cache Redis, one key per studio - never
        // LOCK_DSN, which is a Postgres advisory lock per request. Declared here, next to the watch page's limit below;
        // config/packages/lock.php keeps the default lock.
        'lock' => [
            'online_watch' => '%env(REDIS_CACHE_DSN)%',
        ],
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
            // The 6-digit code from the sign-in e-mail (auth UX redesign phase 2). The
            // real cap is per issued code (5 wrong tries, VerifySignInCodeHandler);
            // these stop a guesser from simply asking for fresh codes (per address)
            // or working through many addresses (per IP; generous for venue NATs).
            'sign_in_code_email' => [
                'policy' => 'sliding_window',
                'limit' => 10,
                'interval' => '15 minutes',
            ],
            'sign_in_code_ip' => [
                'policy' => 'sliding_window',
                'limit' => 30,
                'interval' => '15 minutes',
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
            // Per address: enough for "it did not arrive, send another", no more - bots typing
            // someone's address in sent them 6 confirmations in 10 hours (2026-10-15), and every
            // one more to a flooded inbox is a soft bounce or a complaint against our sending.
            'newsletter_subscribe_email' => [
                'policy' => 'sliding_window',
                'limit' => 2,
                'interval' => '1 day',
            ],
            'newsletter_subscribe_ip' => [
                'policy' => 'sliding_window',
                'limit' => 20,
                'interval' => '1 hour',
            ],
            // Pictures of an event page's sections (gallery photos, sponsor logos) - stored on the CDN,
            // so a page is no free image hosting. Per player: a big gallery an hour, no more.
            'page_section_image_upload' => [
                'policy' => 'sliding_window',
                'limit' => 60,
                'interval' => '1 hour',
            ],
            // Background photo uploads straight to object storage (PhotoUploadStartController,
            // docs/features/photo-uploads/README.md): every new place is a photo in our bucket for a day. Per player:
            // many forms and quick-adds in a row (a re-picked photo counts again), never a free image host.
            'photo_upload' => [
                'policy' => 'sliding_window',
                'limit' => 120,
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
            // The participant API of online competitions (`/api/compete/{token}/…`, OnlineParticipantApi) is limited by
            // RedisSlidingWindowLimiterFactory (config/services.php) - one atomic Redis call per request, so the count
            // holds when a phone (or a flood) sends many requests at the same moment; the test environment stands in
            // with framework limiters of the same names (config/packages/test/rate_limiter.php). The watch page's
            // limit counts the same way (online_rate_limiter.online_watch_ip below).
        ],
    ],
    'services' => [
        // The public watch page of a round's studio: its token endpoint (OnlineWatchTokenController) answers anybody, so
        // it is limited per client IP before anything is read - a page asks once when it opens and again after a lost
        // connection, backing off; a household or a mobile network's shared address stays far below, a script does
        // not. Every token also holds a place in the studio for a few seconds (StudioSpectators), so the limit bounds
        // what one address can take there too. One atomic call on the cache Redis like the participant API's limits
        // (RedisSlidingWindowLimiterFactory, its connection online_rate_limiter.redis - config/services.php); the test
        // environment stands in with the framework limiter `online_watch_ip` (config/packages/test/rate_limiter.php).
        'online_rate_limiter.online_watch_ip' => [
            'class' => \SpeedPuzzling\Web\Services\RateLimiting\RedisSlidingWindowLimiterFactory::class,
            'autowire' => true,
            'arguments' => [
                '$redis' => service('online_rate_limiter.redis'),
                '$id' => 'online_watch_ip',
                '$limit' => 60,
                '$intervalSeconds' => 60,
                '$keyPrefix' => 'online_rate_limit:',
            ],
        ],
    ],
]);
