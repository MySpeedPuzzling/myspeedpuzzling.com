<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use Symfony\Component\HttpFoundation\Session\Storage\Handler\PdoSessionHandler;

return App::config([
    'framework' => [
        'secret' => '%env(APP_SECRET)%',
        'http_method_override' => false,
        'csrf_protection' => true,
        'session' => [
            'handler_id' => PdoSessionHandler::class,
            'cookie_secure' => 'auto',
            'cookie_samesite' => 'lax',
            // 30 days, matching remember_me (config/packages/security.php) so the
            // two agree on how long "stay signed in" means.
            //
            // They are independent mechanisms and BOTH are needed. The session
            // cookie genuinely slides - session_start() re-sends it with a fresh
            // Max-Age on every request - so an active visitor is never signed out.
            // The remember-me cookie does NOT slide while a session is alive:
            // RememberMeAuthenticator::supports() declines whenever a token is
            // already present, so the handler only re-issues on the one path that
            // consumes it (an expired session). Before these matched, someone
            // active for months and then idle fell back to the session's 15.6 days
            // rather than the advertised 30 - their remember-me cookie had expired
            // 30 days after login and was never renewed.
            //
            // Affordable only since anonymous requests stopped creating sessions
            // (docs/features/return-url.md): at ~450 real sessions/day, 30-day
            // retention is on the order of 15k rows. At the previous rate of
            // 200k-1M crawler sessions/day this would have doubled a 1.8 GB table.
            // Existing rows keep their stored sess_lifetime until rewritten, so the
            // legacy anonymous rows still expire on the old, shorter clock.
            'cookie_lifetime' => 2592000,
            'gc_maxlifetime' => 2592000,
            'storage_factory_id' => 'session.storage.factory.native',
        ],
        'php_errors' => [
            'log' => true,
        ],
        'trusted_headers' => ['x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-port', 'x-forwarded-prefix'],
        'trusted_proxies' => '%env(TRUSTED_PROXIES)%',
    ],
]);
