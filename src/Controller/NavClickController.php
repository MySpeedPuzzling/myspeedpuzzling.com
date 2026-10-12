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
