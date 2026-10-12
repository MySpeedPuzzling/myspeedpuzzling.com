<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives the navigation beacons of assets/nav_instrumentation.js (docs/features/navigation, P0-03) and writes one
 * info line per beacon on the `navigation` channel - counted in Loki, never a Sentry issue (prod/monolog.php):
 *
 * - click: a link or button of the chrome carrying data-nav="<surface>/<place>/<item>" was clicked
 * - viewport: once per browser session, the innerWidth bucket and the pointer type
 * - history: a Back/Forward navigation (a Turbo restore, a page loaded by a traverse, a back/forward cache restore)
 * - dialog_suppressed: a native confirm dialog an iPhone answered `false` at once - a dialog Safari refused to show
 *   (GEST-13 watchdog in assets/confirm_dialog.js, once per page view): after a layer was closed by Back, after a
 *   Back/Forward restore of the page
 * - menu_open: the Menu was opened - on which surface (the phone panel MENU-02b, the desktop overlay NAV-13) and on
 *   which place's page (README §4.12 "Measurement")
 * - search_zero: a page search found nothing at all - no page, no player, no puzzle (MENU-03, decision 39): which field
 *   (the Menu panel's, the page's / top bar's) and the query, folded and cut to 40 characters by the script
 *
 * The page's path and the data-nav value only - no cookies, no session (the `stateless` firewall), no user agent.
 * A path segment that looks like a secret (a password-reset, account-deletion, credential-claim, sign-in, newsletter or
 * stash token) is logged as {token} - the script masks it before sending (loggablePage() there), this is the
 * authoritative copy of the rule. Every field is checked against what the script can send; anything else drops the
 * line. Always 204.
 */
final class NavClickController extends AbstractController
{
    /**
     * The data-nav grammar - the script checks the same pattern (NAV_PATTERN in assets/nav_instrumentation.js). \A and
     * \z, never ^ and $: PCRE's $ also matches before a trailing newline, a value the script can never send.
     */
    public const string NAV_PATTERN = '~\A[a-z_]+/[a-z_]+/[a-z0-9_]+\z~';

    /**
     * location.pathname as the script sends it (PAGE_PATTERN in assets/nav_instrumentation.js): RFC 3986 path
     * characters only - a browser percent-encodes everything else, non-ASCII included - and whole {token} segments the
     * script masked. No query string, no fragment, no whole URL.
     */
    public const string PAGE_PATTERN = '~\A(?:/(?:\{token\}|[A-Za-z0-9\-._\~%!$&\'()*+,;=:@]*))+\z~';

    /** What a secret path segment is logged as */
    public const string MASKED_SEGMENT = '{token}';

    /**
     * A secret path segment: 20+ characters of the token alphabets (hex, base62, base64url, dotted signed tokens, Stripe
     * ids) that is neither a UUID nor a slug - player, puzzle and event ids and slugs stay readable. Covers every route
     * with a secret in its path (NavClickControllerTest::secretPaths(), which also fails on a new one).
     */
    private const string TOKEN_SEGMENT = '~\A[A-Za-z0-9._-]{20,}\z~';
    private const string UUID_SEGMENT = '~\A[0-9a-fA-F]{8}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{4}-[0-9a-fA-F]{12}\z~';
    // Lower-case words joined by dashes (at least one), maybe with a file extension
    private const string SLUG_SEGMENT = '~\A[a-z0-9]+(?:-[a-z0-9]+)+(?:\.[a-z0-9]+)?\z~';

    private const int MAX_PAYLOAD_BYTES = 1024;
    private const int MAX_NAV_LENGTH = 100;
    private const int MAX_PAGE_LENGTH = 300;
    private const array LAYOUTS = ['phone', 'desktop'];
    private const array VIEWPORT_BUCKETS = ['lt768', '768_1023', '1024_1279', '1280_1439', '1440_1919', 'gte1920'];
    private const array POINTERS = ['coarse', 'fine', 'none'];
    private const array HISTORY_VIA = ['turbo_restore', 'load', 'bfcache'];
    private const array HISTORY_DIRECTIONS = ['back', 'forward', 'unknown'];
    private const array MENU_SURFACES = ['panel', 'overlay'];
    private const array SEARCH_SURFACES = ['menu', 'topbar'];

    /** The longest search_zero query (SEARCH_QUERY_MAX in assets/nav_instrumentation.js) - anything longer drops the line */
    public const int MAX_QUERY_LENGTH = 40;

    /** NavPlace values - the script sends the same list (PLACES in assets/nav_instrumentation.js) */
    public const array PLACES = ['home', 'puzzles', 'add', 'community', 'profile', 'none'];

    public function __construct(
        #[Autowire(service: 'monolog.logger.navigation')]
        readonly private LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/-/nav-click', name: 'nav_click', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $payload = self::payload($request);

        if ($payload !== null) {
            $page = self::loggablePage($payload['page'] ?? null);

            if ($page !== null) {
                $context = [
                    'page' => $page,
                    'layout' => in_array($payload['layout'] ?? null, self::LAYOUTS, true) ? $payload['layout'] : null,
                    'webdriver' => ($payload['wd'] ?? null) === true,
                ];

                match ($payload['k'] ?? null) {
                    'click' => $this->click($payload, $context),
                    'viewport' => $this->viewport($payload, $context),
                    'history' => $this->history($payload, $context),
                    'dialog_suppressed' => $this->dialogSuppressed($payload, $context),
                    'menu_open' => $this->menuOpen($payload, $context),
                    'search_zero' => $this->searchZero($payload, $context),
                    default => null,
                };
            }
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     */
    private function click(array $payload, array $context): void
    {
        $nav = $payload['nav'] ?? null;

        if (!is_string($nav) || strlen($nav) > self::MAX_NAV_LENGTH || preg_match(self::NAV_PATTERN, $nav) !== 1) {
            return;
        }

        [$surface, $place, $item] = explode('/', $nav);

        $this->logger->info('Navigation click', [
            'kind' => 'click',
            'nav' => $nav,
            'surface' => $surface,
            'place' => $place,
            'item' => $item,
            ...$context,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     */
    private function viewport(array $payload, array $context): void
    {
        $bucket = $payload['bucket'] ?? null;

        if (!in_array($bucket, self::VIEWPORT_BUCKETS, true)) {
            return;
        }

        $this->logger->info('Navigation viewport', [
            'kind' => 'viewport',
            'bucket' => $bucket,
            'pointer' => in_array($payload['pointer'] ?? null, self::POINTERS, true) ? $payload['pointer'] : null,
            'standalone' => ($payload['standalone'] ?? null) === true,
            ...$context,
        ]);
    }

    /**
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     */
    private function history(array $payload, array $context): void
    {
        $via = $payload['via'] ?? null;

        if (!in_array($via, self::HISTORY_VIA, true)) {
            return;
        }

        $this->logger->info('Navigation history', [
            'kind' => 'history',
            'via' => $via,
            'direction' => in_array($payload['dir'] ?? null, self::HISTORY_DIRECTIONS, true) ? $payload['dir'] : 'unknown',
            'ua_visual_transition' => is_bool($payload['uaTransition'] ?? null) ? $payload['uaTransition'] : null,
            ...$context,
        ]);
    }

    /**
     * GEST-13: `afterRestore` is a boolean today (the page was loaded by a traverse, decision 102); GEST-15 sends its
     * restore kind instead - anything this endpoint does not know yet is logged as null, the line stays.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     */
    private function dialogSuppressed(array $payload, array $context): void
    {
        $this->logger->info('Navigation dialog suppressed', [
            'kind' => 'dialog_suppressed',
            'after_layer_back' => ($payload['afterLayerBack'] ?? null) === true,
            'after_restore' => is_bool($payload['afterRestore'] ?? null) ? $payload['afterRestore'] : null,
            ...$context,
        ]);
    }

    /**
     * MENU-02b / NAV-13: one line per open of the Menu. An unknown surface drops the line, an unknown place is logged as
     * null.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     */
    private function menuOpen(array $payload, array $context): void
    {
        $surface = $payload['surface'] ?? null;

        if (!in_array($surface, self::MENU_SURFACES, true)) {
            return;
        }

        $this->logger->info('Navigation menu open', [
            'kind' => 'menu_open',
            'surface' => $surface,
            'place' => in_array($payload['place'] ?? null, self::PLACES, true) ? $payload['place'] : null,
            ...$context,
        ]);
    }

    /**
     * MENU-03 / SRCH-01: one line per page search without any result. An unknown field, an empty query or one longer
     * than the script sends drops the line; control and format characters are dropped from the query.
     *
     * @param array<string, mixed> $payload
     * @param array<string, mixed> $context
     */
    private function searchZero(array $payload, array $context): void
    {
        $surface = $payload['surface'] ?? null;
        $query = $payload['q'] ?? null;

        if (!in_array($surface, self::SEARCH_SURFACES, true) || !is_string($query) || mb_check_encoding($query, 'UTF-8') === false) {
            return;
        }

        $query = trim((string) preg_replace('/[\p{Cc}\p{Cf}]+/u', '', $query));

        if ($query === '' || mb_strlen($query) > self::MAX_QUERY_LENGTH) {
            return;
        }

        $this->logger->info('Navigation search without results', [
            'kind' => 'search_zero',
            'surface' => $surface,
            'query' => $query,
            ...$context,
        ]);
    }

    /**
     * The beacon's JSON object, read up to MAX_PAYLOAD_BYTES - a longer body is not one of ours.
     *
     * @return null|array<string, mixed>
     */
    private static function payload(Request $request): null|array
    {
        $body = stream_get_contents($request->getContent(true), self::MAX_PAYLOAD_BYTES + 1);

        if (!is_string($body) || $body === '' || strlen($body) > self::MAX_PAYLOAD_BYTES) {
            return null;
        }

        $payload = json_decode($body, associative: true, depth: 4);

        if (!is_array($payload) || array_is_list($payload) || ($payload['v'] ?? null) !== 1) {
            return null;
        }

        /** @var array<string, mixed> $payload */
        return $payload;
    }

    /**
     * The page as it is logged: location.pathname - a path, never a query string or a whole URL - with every secret
     * segment replaced by {token}; null drops the line. The script runs the same function (loggablePage()) before it
     * sends anything, so a secret never leaves the browser; this copy is the authoritative one.
     */
    public static function loggablePage(mixed $page): null|string
    {
        if (!is_string($page) || preg_match(self::PAGE_PATTERN, $page) !== 1) {
            return null;
        }

        $masked = implode('/', array_map(self::maskedSegment(...), explode('/', $page)));

        return strlen($masked) <= self::MAX_PAGE_LENGTH ? $masked : null;
    }

    private static function maskedSegment(string $segment): string
    {
        if (
            preg_match(self::TOKEN_SEGMENT, $segment) === 1
            && preg_match(self::UUID_SEGMENT, $segment) !== 1
            && preg_match(self::SLUG_SEGMENT, $segment) !== 1
        ) {
            return self::MASKED_SEGMENT;
        }

        return $segment;
    }
}
