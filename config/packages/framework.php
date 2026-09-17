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
            // Neither cookie renews itself, and an earlier version of this comment
            // wrongly claimed the session cookie did. It does not: PHP only emits
            // Set-Cookie for a session id it generated, and AbstractSessionListener
            // re-sends it only when the id changes. The remember-me cookie is
            // likewise only re-issued when it is consumed, which never happens
            // while a session is alive. Both were minted in the same instant at
            // login, so they expired together exactly 30 days later however active
            // the visitor had been - the sliding server-side row could not help,
            // because the browser had stopped sending the id.
            //
            // SlidingLoginCookiesSubscriber is what makes the window actually
            // slide, re-sending both cookies (at most once a day) for signed-in
            // visitors. These two values stay in step because that subscriber
            // renews them together.
            //
            // Affordable only since anonymous requests stopped creating sessions
            // (docs/features/return-url.md): at ~450 real sessions/day, 30-day
            // retention is on the order of 15k rows. At the previous rate of
            // 200k-1M crawler sessions/day this would have doubled a 1.8 GB table.
            // Existing rows keep their stored sess_lifetime until rewritten, so the
            // legacy anonymous rows still expire on the old, shorter clock.
            'cookie_lifetime' => '%loginLifetimeSeconds%',
            'gc_maxlifetime' => '%loginLifetimeSeconds%',
            'storage_factory_id' => 'session.storage.factory.native',
        ],
        'php_errors' => [
            'log' => true,
        ],
        'trusted_headers' => ['x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-port', 'x-forwarded-prefix'],
        'trusted_proxies' => '%env(TRUSTED_PROXIES)%',
    ],
]);
