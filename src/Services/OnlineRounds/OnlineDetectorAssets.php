<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use Symfony\Component\DependencyInjection\Attribute\Autowire;

/**
 * Where the participant's page loads the finish detector from (docs/features/online-competitions/README.md §5.7 "Tech",
 * implementation-plan.md WP 2.1) - the parameter `onlineDetectorAssets` (config/services.php), handed to the page as its
 * detection config (CompetePageController): the MediaPipe tasks-vision ESM bundle, its WASM base, the pose and the
 * gesture model. Pinned versions; the prototype loads them from jsDelivr and Google's model bucket, hosting them
 * ourselves later changes only the parameter. Never under /build/ (the image carries old /build files for a week and the
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
     * @param array<mixed> $assets
     */
    public function __construct(
        #[Autowire(param: 'onlineDetectorAssets')]
        array $assets,
    ) {
        $urls = [];

        foreach (self::KEYS as $key) {
            $url = $assets[$key] ?? null;

            if (!is_string($url) || preg_match('#^https://\S+$#', $url) !== 1) {
                throw new \InvalidArgumentException(sprintf('onlineDetectorAssets.%s must be an https URL', $key));
            }

            $urls[$key] = $url;
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
}
