<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives sendBeacon reports from the inline stale-document script in
 * base.html.twig: a real navigation (not back/forward) was answered with HTML
 * rendered minutes or days ago, measured against the server's own clock.
 *
 * Nothing on the server can see this. The v6 service worker answered every
 * Chromium navigation from a cache for months - people had to refresh twice to
 * see a time they had just added - and the only signal was users writing in.
 * Logged at warning - the lowest level that becomes a Sentry issue.
 */
final class StaleDocumentController extends AbstractController
{
    private const int MAX_PAYLOAD_BYTES = 2048;

    public function __construct(
        readonly private LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/-/stale-document', name: 'stale_document', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $payload = json_decode(
            substr($request->getContent(), 0, self::MAX_PAYLOAD_BYTES),
            associative: true,
        );

        if (is_array($payload)) {
            $page = $payload['page'] ?? null;
            $ageSeconds = $payload['age'] ?? null;

            if (is_string($page) && is_int($ageSeconds) && $ageSeconds > 0) {
                $context = [
                    'page' => mb_substr($page, 0, 500),
                    'age_seconds' => $ageSeconds,
                    'navigation_type' => is_string($payload['type'] ?? null) ? mb_substr($payload['type'], 0, 20) : null,
                    'delivery_type' => is_string($payload['delivery'] ?? null) ? mb_substr($payload['delivery'], 0, 40) : null,
                    'transfer_size' => is_int($payload['transferSize'] ?? null) ? $payload['transferSize'] : null,
                    'through_service_worker' => ($payload['throughWorker'] ?? null) === true,
                    'tab_was_discarded' => ($payload['discarded'] ?? null) === true,
                    'still_stale_after_heal' => ($payload['retry'] ?? null) === true,
                    'user_agent' => $request->headers->get('User-Agent'),
                ];

                // deliveryType "cache" is the browser's own HTTP cache deciding to
                // reuse the page (restoring a closed or discarded tab does that
                // whatever Cache-Control says) - not something this app served wrong.
                if ($context['delivery_type'] === 'cache' && $context['still_stale_after_heal'] === false) {
                    $this->logger->info('Client restored a stale HTML document from its HTTP cache', $context);
                } else {
                    $this->logger->warning('Client was shown a stale HTML document on a real navigation (service worker answered from a cache?)', $context);
                }
            }
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
