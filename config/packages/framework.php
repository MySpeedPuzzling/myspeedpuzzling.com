<?php

declare(strict_types=1);

namespace Symfony\Component\DependencyInjection\Loader\Configurator;

use SpeedPuzzling\Web\Exceptions\CompetitionNotApprovable;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundHasResults;
use SpeedPuzzling\Web\Exceptions\CompetitionSlugAmbiguous;
use SpeedPuzzling\Web\Exceptions\CompetitionSlugTaken;
use SpeedPuzzling\Web\Exceptions\CompetitionTagShared;
use SpeedPuzzling\Web\Exceptions\PuzzleChangedMeanwhile;
use SpeedPuzzling\Web\Exceptions\PuzzleChangeRequestAlreadyReviewed;
use SpeedPuzzling\Web\Exceptions\PuzzleEanAlreadyInCatalogue;
use SpeedPuzzling\Web\Exceptions\PuzzleInTwoRoundsOfCategory;
use SpeedPuzzling\Web\Services\Session\PostgresSessionHandler;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;
use Symfony\Component\HttpKernel\Exception\NotAcceptableHttpException;
use Symfony\Component\HttpKernel\Exception\UnsupportedMediaTypeHttpException;

return App::config([
    'framework' => [
        'secret' => '%env(APP_SECRET)%',
        'http_method_override' => false,
        'csrf_protection' => true,
        'session' => [
            'handler_id' => PostgresSessionHandler::class,
            // The metadata bag stamps "last used" into the session data. With the
            // default 0 it did so on every request, so every request rewrote the
            // row. Now it re-stamps at most once an hour, and a request writes the
            // row only when something in the session changed or that hour is up -
            // which is also what keeps the row's 30-day expiry sliding
            // (PostgresSessionHandler::updateTimestamp()). Nothing in the app
            // reads "last used".
            'metadata_update_threshold' => 3600,
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
        // A request the client got wrong, already answered with its 4xx: a bot's garbage body, an API
        // client sending the wrong format, an internal-API call missing a field. Symfony logs uncaught
        // 4xx at error, which made each one a Sentry issue; info keeps them out (warning and above
        // become issues). Only the log level changes - unlike Sentry's ignore_exceptions, which
        // drops an event when ANY exception of its chain matches. Code that knows a 400 means a bug
        // logs it at error itself (StaleLiveComponentPageSubscriber).
        'exceptions' => [
            BadRequestHttpException::class => ['log_level' => 'info'],
            NotAcceptableHttpException::class => ['log_level' => 'info'],
            UnsupportedMediaTypeHttpException::class => ['log_level' => 'info'],
            // Moderators racing each other (or a stale form / internal-API call): the request was decided or the
            // puzzle changed after it was read - answered 409 / 422 with "read it again", nothing applied
            PuzzleChangeRequestAlreadyReviewed::class => ['log_level' => 'info'],
            PuzzleChangedMeanwhile::class => ['log_level' => 'info'],
            // The internal API's refusals of an event change that breaks a rule - answered 409 with the reason,
            // nothing applied (docs/features/internal-api.md, Competitions and events)
            CompetitionSlugTaken::class => ['log_level' => 'info'],
            CompetitionSlugAmbiguous::class => ['log_level' => 'info'],
            CompetitionNotApprovable::class => ['log_level' => 'info'],
            CompetitionRoundHasResults::class => ['log_level' => 'info'],
            CompetitionTagShared::class => ['log_level' => 'info'],
            PuzzleInTwoRoundsOfCategory::class => ['log_level' => 'info'],
            PuzzleEanAlreadyInCatalogue::class => ['log_level' => 'info'],
        ],
        'trusted_headers' => ['x-forwarded-for', 'x-forwarded-host', 'x-forwarded-proto', 'x-forwarded-port', 'x-forwarded-prefix'],
        'trusted_proxies' => '%env(TRUSTED_PROXIES)%',
    ],
]);
