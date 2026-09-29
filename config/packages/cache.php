<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

return App::config([
    'framework' => [
        'cache' => [
            'default_redis_provider' => '%env(REDIS_CACHE_DSN)%',
            'app' => 'cache.adapter.redis',
            'pools' => [
                // Per-request dedup markers for daily activity tracking - one
                // marker per user per day so the terminate subscriber costs one
                // cache read per request instead of a DB write
                'player_activity_cache' => [
                    'adapters' => ['cache.app'],
                ],
                // OAuth state + PKCE + rule-4 parked profiles for social login.
                // Server-side instead of the session on purpose: Apple's form_post
                // callback is a cross-site POST that arrives without SameSite=Lax
                // session cookies, and the anonymous start route must stay
                // session-free (#164)
                'social_login_state_cache' => [
                    'adapters' => ['cache.app'],
                ],
                // Short-lived single-use codes handed to WJPF at the end of the
                // manual pairing flow. Cache rather than a table: they live ten
                // minutes, self-expire, and the lasting record of who linked when
                // is the wjpf_identity row, not the code.
                // "This browser just signed in with its code" for 60 s, so a
                // duplicate submit of the same code redirects instead of failing
                // (SignInCodeCompletion) - the session it came with is gone
                'sign_in_code_completion_cache' => [
                    'adapters' => ['cache.app'],
                ],
                'wjpf_pairing_code_cache' => [
                    'adapters' => ['cache.app'],
                ],
                // Community solve-time distributions behind the guides and the FAQ
                // (SolveTimeDistributionProvider) - one entry per puzzling type,
                // recomputed every 6 hours.
                'solve_time_distribution_cache' => [
                    'adapters' => ['cache.app'],
                ],
            ],
        ],
    ],
]);
