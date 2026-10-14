<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\OnlineRounds;

use SpeedPuzzling\Web\Services\OnlineRounds\OnlineDetectorAssets;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * Where the finish detector comes from (docs/features/online-competitions/README.md §5.7, implementation-plan.md WP
 * 2.1): the parameter `onlineDetectorAssets` is the one place for the participant's page; the bench pins the same
 * versions in assets/online/hands_up_signal.js DETECTOR_ASSETS - both must name the same files, or the page would run
 * other models than the ones the thresholds were tuned on.
 */
final class OnlineDetectorAssetsTest extends KernelTestCase
{
    public function testThePageLoadsWhatTheBenchMeasured(): void
    {
        $urls = self::getContainer()->get(OnlineDetectorAssets::class)->urls();

        self::assertSame(self::benchAssets(), $urls);

        foreach ($urls as $key => $url) {
            self::assertStringNotContainsString('latest', $url, $key);
            self::assertStringStartsWith('https://', $url, $key);
        }

        self::assertStringContainsString('@mediapipe/tasks-vision@1.1.0/', $urls['tasksVisionUrl']);
        self::assertStringContainsString('/float16/1/', $urls['poseModelUrl']);
        self::assertStringContainsString('/float16/1/', $urls['gestureModelUrl']);
    }

    public function testAnythingButHttpsUrlsIsRefused(): void
    {
        $valid = self::benchAssets();

        foreach (['missing' => [...array_diff_key($valid, ['wasmBaseUrl' => true])], 'plain http' => [...$valid, 'poseModelUrl' => 'http://example.com/pose.task'], 'not a string' => [...$valid, 'tasksVisionUrl' => 12]] as $case => $assets) {
            try {
                new OnlineDetectorAssets($assets);
                self::fail($case . ' was accepted');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /**
     * DETECTOR_ASSETS of hands_up_signal.js, its version filled in
     *
     * @return array<string, string>
     */
    private static function benchAssets(): array
    {
        $source = (string) file_get_contents(__DIR__ . '/../../../assets/online/hands_up_signal.js');
        self::assertSame(1, preg_match("/export const TASKS_VISION_VERSION = '([^']+)';/", $source, $version));
        self::assertSame(1, preg_match('/export const DETECTOR_ASSETS = Object\.freeze\(\{(.*?)\}\);/s', $source, $block));
        self::assertSame(4, preg_match_all("/(\\w+): [`']([^`']+)[`'],/", $block[1], $entries, PREG_SET_ORDER));

        $assets = [];

        foreach ($entries as [, $key, $url]) {
            $assets[$key] = str_replace('${TASKS_VISION_VERSION}', $version[1], $url);
        }

        return $assets;
    }
}
