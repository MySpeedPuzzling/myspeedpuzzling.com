<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\OnlineRounds;

use SpeedPuzzling\Web\Services\OnlineRounds\OnlineDetectorAssets;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Where the finish detector comes from (docs/features/online-competitions/README.md §5.7 "Tech" and "Downloads",
 * implementation-plan.md WP 2.1): the parameter `onlineDetectorAssets` is the one place for the participant's page; the
 * bench pins the same versions in assets/online/hands_up_signal.js DETECTOR_ASSETS - both must name the same files, or
 * the page would run other models than the ones the thresholds were tuned on. Production serves its own copy of exactly
 * those files (bin/fetch-online-detector.mjs, the Dockerfile's `online-detector` stage) and points
 * ONLINE_DETECTOR_BASE_URL at it - every pin of that copy must agree with the CDN URLs, package-lock.json and the
 * Dockerfile.
 */
final class OnlineDetectorAssetsTest extends KernelTestCase
{
    private const string ROOT = __DIR__ . '/../../..';
    private const string ENV = 'ONLINE_DETECTOR_BASE_URL';

    /** @var null|array{server: mixed, env: mixed} */
    private null|array $environment = null;

    protected function tearDown(): void
    {
        $environment = $this->environment;

        if ($environment !== null) {
            self::restore($_SERVER, $environment['server']);
            self::restore($_ENV, $environment['env']);
        }

        parent::tearDown();
    }

    /**
     * @param array<mixed> $variables
     */
    private static function restore(array &$variables, mixed $value): void
    {
        if ($value === null) {
            unset($variables[self::ENV]);
        } else {
            $variables[self::ENV] = $value;
        }
    }

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

    public function testOurCopyKeepsTheFileNamesOfTheCdnPaths(): void
    {
        $directory = '/online-detector/' . self::pins()['directory'];

        foreach ([$directory, $directory . '/', ' ' . $directory . ' '] as $base) {
            self::assertSame([
                'tasksVisionUrl' => $directory . '/vision_bundle.mjs',
                'wasmBaseUrl' => $directory . '/wasm',
                'poseModelUrl' => $directory . '/pose_landmarker_lite.task',
                'gestureModelUrl' => $directory . '/gesture_recognizer.task',
            ], new OnlineDetectorAssets(self::benchAssets(), $base)->urls(), $base);
        }

        self::assertSame([
            'tasksVisionUrl' => 'https://detector.example.com/msp/1/vision_bundle.mjs',
            'wasmBaseUrl' => 'https://detector.example.com/msp/1/wasm',
            'poseModelUrl' => 'https://detector.example.com/msp/1/pose_landmarker_lite.task',
            'gestureModelUrl' => 'https://detector.example.com/msp/1/gesture_recognizer.task',
        ], new OnlineDetectorAssets(self::benchAssets(), 'https://detector.example.com/msp/1')->urls());

        self::assertSame(self::benchAssets(), new OnlineDetectorAssets(self::benchAssets(), '  ')->urls(), 'empty = the CDNs');
    }

    public function testABaseThatIsNeitherHttpsNorFromTheSiteRootIsRefused(): void
    {
        foreach (['http://detector.example.com/msp', '//detector.example.com/msp', '/\\detector.example.com', 'online-detector/1', 'https://', '/online-detector/1?v=2', '/online-detector/1#x', 'javascript:alert(1)'] as $base) {
            try {
                new OnlineDetectorAssets(self::benchAssets(), $base);
                self::fail($base . ' was accepted');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function testTheBaseComesFromTheEnvironment(): void
    {
        $this->environment = ['server' => $_SERVER[self::ENV] ?? null, 'env' => $_ENV[self::ENV] ?? null];
        $_SERVER[self::ENV] = $_ENV[self::ENV] = '/online-detector/' . self::pins()['directory'] . '/';
        self::ensureKernelShutdown();
        self::bootKernel();

        $urls = self::getContainer()->get(OnlineDetectorAssets::class)->urls();

        self::assertSame('/online-detector/' . self::pins()['directory'] . '/vision_bundle.mjs', $urls['tasksVisionUrl']);
        self::ensureKernelShutdown();
    }

    /**
     * The copy production serves is the files the CDN URLs name: the same npm tarball package-lock.json pins (version,
     * URL, integrity), the same model URLs, every file the URLs point at written by the script, the image serving the
     * script's directory.
     */
    public function testTheCopyIsTheFilesTheCdnUrlsName(): void
    {
        $pins = self::pins();
        $cdn = self::getContainer()->get(OnlineDetectorAssets::class)->urls();
        $tasksVision = $pins['tasksVision'];

        /** @var array{dependencies: array<string, string>} $package */
        $package = json_decode((string) file_get_contents(self::ROOT . '/package.json'), true, flags: JSON_THROW_ON_ERROR);
        /** @var array{packages: array<string, array{version?: string, resolved?: string, integrity?: string}>} $lock */
        $lock = json_decode((string) file_get_contents(self::ROOT . '/package-lock.json'), true, flags: JSON_THROW_ON_ERROR);
        $locked = $lock['packages']['node_modules/@mediapipe/tasks-vision'] ?? [];

        self::assertSame($tasksVision['version'], $package['dependencies']['@mediapipe/tasks-vision'] ?? null, 'package.json pins the exact version');
        self::assertSame($tasksVision['version'], $locked['version'] ?? null);
        self::assertSame($tasksVision['tarball'], $locked['resolved'] ?? null);
        self::assertSame($tasksVision['integrity'], $locked['integrity'] ?? null, 'the tarball the image copies is the one npm verified');

        self::assertStringContainsString('/@mediapipe/tasks-vision@' . $tasksVision['version'] . '/', $cdn['tasksVisionUrl']);
        self::assertStringContainsString('/@mediapipe/tasks-vision@' . $tasksVision['version'] . '/', $cdn['wasmBaseUrl']);
        self::assertStringStartsWith($tasksVision['version'] . '-', $pins['directory']);
        self::assertSame(
            [$cdn['poseModelUrl'], $cdn['gestureModelUrl']],
            array_column($pins['models'], 'url'),
            'the models the bench measured',
        );

        $written = [...$tasksVision['files'], ...array_column($pins['models'], 'file')];
        $ours = new OnlineDetectorAssets(self::benchAssets(), '/online-detector/' . $pins['directory'])->urls();
        $prefix = '/online-detector/' . $pins['directory'] . '/';

        foreach (['tasksVisionUrl', 'poseModelUrl', 'gestureModelUrl'] as $key) {
            self::assertContains(substr($ours[$key], strlen($prefix)), $written, $key);
        }

        // Both WASM loaders, the classic and the module worker's, with and without SIMD (FilesetResolver)
        foreach (['vision_wasm_internal', 'vision_wasm_module_internal', 'vision_wasm_nosimd_internal'] as $name) {
            foreach (['.js', '.wasm'] as $extension) {
                self::assertContains(substr($ours['wasmBaseUrl'], strlen($prefix)) . '/' . $name . $extension, $written);
            }
        }

        $dockerfile = (string) file_get_contents(self::ROOT . '/Dockerfile');

        self::assertStringContainsString("\nENV ONLINE_DETECTOR_BASE_URL=/online-detector/" . $pins['directory'] . "\n", $dockerfile, 'the image points the page at the directory the script fills');
        self::assertStringContainsString("\nRUN node /src/bin/fetch-online-detector.mjs --precompress /online-detector\n", $dockerfile);
        self::assertStringContainsString("\nCOPY --from=online-detector /online-detector public/online-detector\n", $dockerfile);
        self::assertStringContainsString("\nCOPY .docker/online-detector/online-detector.caddyfile /etc/frankenphp/Caddyfile.d/\n", $dockerfile);
    }

    /**
     * DETECTOR_ASSETS of hands_up_signal.js, its version filled in
     *
     * @return array<string, string>
     */
    private static function benchAssets(): array
    {
        $source = (string) file_get_contents(self::ROOT . '/assets/online/hands_up_signal.js');
        self::assertSame(1, preg_match("/export const TASKS_VISION_VERSION = '([^']+)';/", $source, $version));
        self::assertSame(1, preg_match('/export const DETECTOR_ASSETS = Object\.freeze\(\{(.*?)\}\);/s', $source, $block));
        self::assertSame(4, preg_match_all("/(\\w+): [`']([^`']+)[`'],/", $block[1], $entries, PREG_SET_ORDER));

        $assets = [];

        foreach ($entries as [, $key, $url]) {
            $assets[$key] = str_replace('${TASKS_VISION_VERSION}', $version[1], $url);
        }

        return $assets;
    }

    /**
     * The pins of bin/fetch-online-detector.mjs, as the script itself prints them
     *
     * @return array{directory: string, tasksVision: array{version: string, tarball: string, integrity: string, files: list<string>}, models: list<array{file: string, url: string, bytes: int, sha256: string}>}
     */
    private static function pins(): array
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to read the pins - it is part of the base image');

        $process = new Process([$node, self::ROOT . '/bin/fetch-online-detector.mjs', '--pins']);
        $process->mustRun();

        /** @var array{directory: string, tasksVision: array{version: string, tarball: string, integrity: string, files: list<string>}, models: list<array{file: string, url: string, bytes: int, sha256: string}>} $pins */
        $pins = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $pins;
    }
}
