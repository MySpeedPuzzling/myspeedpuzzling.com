<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives sendBeacon reports from the inline asset-failure script in
 * base.html.twig. A client whose cached /build bundle is corrupt gets it
 * silently refused by SRI on every page load — the browser Sentry SDK lives
 * inside that dead bundle, so this endpoint is the only way to hear about it.
 * Logged at warning so it flushes through fingers_crossed into Sentry.
 */
final class AssetLoadFailureController extends AbstractController
{
    private const int MAX_PAYLOAD_BYTES = 2048;

    public function __construct(
        readonly private LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/-/asset-load-failure', name: 'asset_load_failure', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $payload = json_decode(
            substr($request->getContent(), 0, self::MAX_PAYLOAD_BYTES),
            associative: true,
        );

        if (is_array($payload)) {
            $assetUrl = $payload['url'] ?? null;
            $page = $payload['page'] ?? null;

            if (is_string($assetUrl) && str_contains($assetUrl, '/build/')) {
                $this->logger->warning('Client failed to load a build asset (corrupt cache / SRI rejection or network failure)', [
                    'asset_url' => mb_substr($assetUrl, 0, 500),
                    'page' => is_string($page) ? mb_substr($page, 0, 500) : null,
                    'sw_controlled' => ($payload['controlled'] ?? null) === true,
                    'retry_after_heal' => ($payload['retry'] ?? null) === true,
                    'user_agent' => $request->headers->get('User-Agent'),
                ]);
            }
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
