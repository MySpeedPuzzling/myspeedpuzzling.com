<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Where the participant's page loads the finish detector from (docs/features/online-competitions/README.md §5.7 "Tech"
 * and "Downloads", implementation-plan.md WP 2.1), handed to the page as its detection config (CompetePageController):
 * the MediaPipe tasks-vision ESM bundle, its WASM base, the pose and the gesture model.
 *
 * The parameter `onlineDetectorAssets` (config/services.php) pins them on their CDNs - jsDelivr and Google's model
 * bucket, the URLs the bench measured. ONLINE_DETECTOR_BASE_URL (the parameter `onlineDetectorBaseUrl`) moves all four
 * to our own copy: empty = the CDNs (dev, tests, the bench); else the directory bin/fetch-online-detector.mjs fills -
 * the production image serves it itself at /online-detector/<version>-<models>/ (a root-relative path: same origin, no
 * CORS) - or an https URL of another copy. The files keep the CDN paths' names: the bundle and the models by their
 * file names, the WASM base as `wasm`. Never under /build/ (the image carries old /build files for a week and the
 * service worker buffers whole bodies - README §5.7 "Downloads").
 */
final readonly class OnlineDetectorAssets
{
    public const array KEYS = ['tasksVisionUrl', 'wasmBaseUrl', 'poseModelUrl', 'gestureModelUrl'];

    /**
     * @var array{tasksVisionUrl: string, wasmBaseUrl: string, poseModelUrl: string, gestureModelUrl: string}
     */
    private array $urls;

    /**
     * @param array<mixed> $assets the pinned CDN URLs
     * @param string $baseUrl where our copy lies - empty = the CDN URLs
     */
    public function __construct(
        #[Autowire(param: 'onlineDetectorAssets')]
        array $assets,
        #[Autowire(param: 'onlineDetectorBaseUrl')]
        string $baseUrl = '',
    ) {
        $urls = [];

        foreach (self::KEYS as $key) {
            $url = $assets[$key] ?? null;

            if (!is_string($url) || preg_match('#^https://[^/\s]+/\S+$#', $url) !== 1) {
                throw new \InvalidArgumentException(sprintf('onlineDetectorAssets.%s must be an https URL', $key));
            }

            $urls[$key] = $url;
        }

        $base = trim($baseUrl);

        if ($base !== '') {
            // An https URL or a path from the site's root - never protocol-relative (`//host`), never a query or fragment
            if (preg_match('~^(https://[^/\s?#\\\\]+|/(?![/\\\\]))[^\s?#\\\\]*$~', $base) !== 1) {
                throw new \InvalidArgumentException('ONLINE_DETECTOR_BASE_URL must be an https URL or a path from the site\'s root (/…)');
            }

            $base = rtrim($base, '/');

            foreach ($urls as $key => $url) {
                $urls[$key] = $base . '/' . self::fileName($url);
            }
        }

        $this->urls = $urls;
    }

    /**
     * @return array{tasksVisionUrl: string, wasmBaseUrl: string, poseModelUrl: string, gestureModelUrl: string}
     */
    public function urls(): array
    {
        return $this->urls;
    }

    /**
     * The last segment of a pinned CDN URL - `vision_bundle.mjs`, `wasm`, `pose_landmarker_lite.task`, …
     */
    private static function fileName(string $url): string
    {
        $path = (string) parse_url($url, PHP_URL_PATH);

        return basename($path);
    }
}
