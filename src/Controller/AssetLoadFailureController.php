<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use SpeedPuzzling\Web\Services\AssetLoadFailureClassifier;
use SpeedPuzzling\Web\Value\AssetLoadFailureReport;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Receives sendBeacon reports from the inline asset-failure script in
 * base.html.twig. A client whose cached /build bundle is corrupt gets it
 * silently refused by SRI on every page load — the browser Sentry SDK lives
 * inside that dead bundle, so this endpoint is the only way to hear about it.
 *
 * Only reports someone can act on are logged at warning, the lowest level that
 * becomes a Sentry issue: the rest (crawlers, pages older than the asset
 * retention, a first failure the self-heal is already repairing) arrived at
 * ~1,500 a day and would bury everything else. They stay at info, so their
 * volume is still visible in the logs - see AssetLoadFailureClassifier.
 */
final class AssetLoadFailureController extends AbstractController
{
    private const int MAX_PAYLOAD_BYTES = 2048;

    public function __construct(
        readonly private LoggerInterface $logger,
        readonly private AssetLoadFailureClassifier $classifier,
    ) {
    }

    #[Route(path: '/-/asset-load-failure', name: 'asset_load_failure', methods: ['POST'])]
    public function __invoke(Request $request): Response
    {
        $payload = json_decode(
            substr($request->getContent(), 0, self::MAX_PAYLOAD_BYTES),
            associative: true,
        );

        $report = is_array($payload) ? AssetLoadFailureReport::fromBeacon($payload, $request->headers->get('User-Agent')) : null;

        if ($report !== null) {
            $verdict = $this->classifier->classify($report);

            $this->logger->log($verdict->isActionable() ? LogLevel::WARNING : LogLevel::INFO, $verdict->logMessage(), [
                'verdict' => $verdict->value,
                'asset_url' => $report->assetUrl,
                'page' => $report->page,
                'page_age_seconds' => $this->classifier->pageAgeSeconds($report),
                'sw_controlled' => $report->serviceWorkerControlled,
                'retry_after_heal' => $report->retry,
                'healing' => $report->healing,
                'refetch' => $report->refetch,
                'webdriver' => $report->webdriver,
                'script_version' => $report->scriptVersion,
                'user_agent' => $report->userAgent,
            ]);
        }

        return new Response(status: Response::HTTP_NO_CONTENT);
    }
}
