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
 * Receives reports from the service worker (public/service-worker.js) when a
 * page navigation's fetch() rejected and it was asked again.
 *
 * Safari rejects a navigation with "Load failed" when it reuses a connection
 * that died without it noticing (seen 2026-10-06 after a tab sat in the
 * background for an hour), and the worker used to answer every rejection with
 * "You are offline" - to a person who was browsing other sites just fine.
 *
 * - recovered: the retry worked, the visitor saw the page. Info on its own
 *   channel so prod writes every one of them (fingers_crossed would drop a
 *   lone info) and they can be counted in Loki without reaching Sentry.
 * - offline-page: the retry failed too and the offline page was shown. This
 *   report arriving means the device was online after all - warning, the
 *   lowest level that becomes a Sentry issue.
 */
final class NavigationFetchFailureController extends AbstractController
{
    private const int MAX_PAYLOAD_BYTES = 2048;

    public function __construct(
        #[Autowire(service: 'monolog.logger.service_worker')]
        readonly private LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/-/navigation-fetch-failure', name: 'navigation_fetch_failure', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $payload = json_decode(
            substr($request->getContent(), 0, self::MAX_PAYLOAD_BYTES),
            associative: true,
        );

        if (is_array($payload)) {
            $outcome = $payload['outcome'] ?? null;
            $page = $payload['page'] ?? null;

            if (in_array($outcome, ['recovered', 'offline-page'], true) && is_string($page)) {
                $context = [
                    'page' => mb_substr($page, 0, 500),
                    'error' => is_string($payload['error'] ?? null) ? mb_substr($payload['error'], 0, 200) : null,
                    'retry_error' => is_string($payload['retryError'] ?? null) ? mb_substr($payload['retryError'], 0, 200) : null,
                    'retry_delay_ms' => is_int($payload['retryDelayMs'] ?? null) ? $payload['retryDelayMs'] : null,
                    'user_agent' => $request->headers->get('User-Agent'),
                ];

                if ($outcome === 'recovered') {
                    $this->logger->info('Service worker navigation failed once and recovered on retry', $context);
                } else {
                    $this->logger->warning('Service worker showed the offline page to a client that was online (navigation failed twice)', $context);
                }
            }
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
