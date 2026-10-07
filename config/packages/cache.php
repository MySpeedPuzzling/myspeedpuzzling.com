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
                // Public hardest / easiest puzzle lists (PuzzleDifficultyRankings):
                // the same for every visitor, cached for an hour. Its own pool so
                // the lists can be flushed on their own
                // (cache:pool:clear difficulty_rankings_cache).
                'difficulty_rankings_cache' => [
                    'adapters' => ['cache.app'],
                ],
                // Global improvement ratios as they were at the start of a month
                // (PredictionReconstructor) - recomputing one takes a second or two
                // over every repeat solve, and the messenger worker resets the
                // in-memory copy after each message. 7-day TTL.
                'global_improvement_ratio_snapshot_cache' => [
                    'adapters' => ['cache.app'],
                ],
                // Marketplace at events: the upcoming events sellers are going to, with
                // their bringing / to-ask counts (GetEventsWithSellersGoing) - the same
                // for every visitor, 10 minutes, keyed by the day.
                'marketplace_events_cache' => [
                    'adapters' => ['cache.app'],
                ],
                // Name tag QR codes as SVG (NameTagQrCode), one per participant URL - the same forever, while drawing
                // one costs ~15 ms: a sheet of a few hundred tags would hold a worker for seconds on every view.
                // 90-day TTL.
                'name_tag_qr_cache' => [
                    'adapters' => ['cache.app'],
                ],
            ],
        ],
    ],
]);
