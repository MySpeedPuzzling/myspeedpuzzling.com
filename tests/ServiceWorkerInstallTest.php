<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * What the real public/service-worker.js precaches on install (tests/service-worker-install-harness.js):
 * every Encore entry's files except the entries in PRECACHE_SKIP_ENTRIES - pages a handful of devices open, never
 * downloaded by every visitor (toolkit R §2.8): `display` (the competition timer screens and stream overlays),
 * `live_entry` (the referees' live result entry - their devices store its offline copy and files on purpose, toolkit
 * C6 `live-shell:prepare`) and `live_entry_native` (Turbo for that page in the native apps, which run no worker).
 *
 * The list goes to cache.addAll() once per URL: browsers refuse a list holding the same request twice as a whole, and
 * every entry lists runtime.js - the precache did nothing at all while it passed each entry's list as it was.
 */
final class ServiceWorkerInstallTest extends TestCase
{
    private const array ENTRYPOINTS = [
        'entrypoints' => [
            'app' => ['js' => ['/build/runtime.1.js', '/build/9045.2.js', '/build/app.3.js'], 'css' => ['/build/9045.4.css', '/build/app.5.css']],
            'live_entry' => ['js' => ['/build/runtime.1.js', '/build/live_entry.6.js']],
            'live_entry_native' => ['js' => ['/build/runtime.1.js', '/build/live_entry_native.10.js']],
            'compete' => ['js' => ['/build/runtime.1.js'], 'css' => ['/build/compete.7.css']],
            'display' => ['js' => ['/build/runtime.1.js', '/build/display.8.js'], 'css' => ['/build/display.9.css']],
            'analytics_studio' => ['js' => ['/build/runtime.1.js', '/build/analytics_studio.11.js'], 'css' => ['/build/analytics_studio.12.css']],
        ],
    ];

    public function testEveryEntryButTheSkippedOnesIsPrecachedOnce(): void
    {
        $result = $this->install(self::ENTRYPOINTS);

        self::assertSame([], $result['rejected']);
        self::assertContains([
            '/build/runtime.1.js',
            '/build/9045.2.js',
            '/build/app.3.js',
            '/build/9045.4.css',
            '/build/app.5.css',
        ], $result['added']);
    }

    public function testTheDisplayEntryIsNeverPrecached(): void
    {
        $added = array_merge(...$this->install(self::ENTRYPOINTS)['added']);

        self::assertNotContains('/build/display.8.js', $added);
        self::assertNotContains('/build/display.9.css', $added);
        self::assertContains('/build/runtime.1.js', $added, 'a file another entry needs too is still precached');
    }

    public function testTheLiveEntryIsNeverPrecachedForEveryVisitor(): void
    {
        $added = array_merge(...$this->install(self::ENTRYPOINTS)['added']);

        self::assertNotContains('/build/live_entry.6.js', $added);
        self::assertNotContains('/build/live_entry_native.10.js', $added);
    }

    public function testTheOnlineCompetitionStylesAreNeverPrecachedForEveryVisitor(): void
    {
        $added = array_merge(...$this->install(self::ENTRYPOINTS)['added']);

        self::assertNotContains('/build/compete.7.css', $added);
    }

    /**
     * The analytics studio (docs/features/competitions-management/analytics-studio.md §7.6): commentators' screens only.
     */
    public function testTheStudioEntryIsNeverPrecached(): void
    {
        $added = array_merge(...$this->install(self::ENTRYPOINTS)['added']);

        self::assertNotContains('/build/analytics_studio.11.js', $added);
        self::assertNotContains('/build/analytics_studio.12.css', $added);
    }

    public function testTheSkipListNamesTheVenueAndRefereeEntries(): void
    {
        $serviceWorker = (string) file_get_contents(__DIR__ . '/../public/service-worker.js');

        self::assertSame(1, preg_match("/^const PRECACHE_SKIP_ENTRIES = \\['display', 'live_entry', 'live_entry_native', 'compete', 'online_rounds', 'online_watch', 'analytics_studio'\\];$/m", $serviceWorker));
    }

    /**
     * @param array<string, mixed> $entrypoints
     * @return array{added: list<list<string>>, rejected: list<string>}
     */
    private function install(array $entrypoints): array
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/service-worker-install-harness.js']);
        $process->setInput((string) json_encode(['entrypoints' => $entrypoints, 'manifest' => (object) []]));
        $process->mustRun();

        /** @var array{added: list<list<string>>, rejected: list<string>} $result */
        $result = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $result;
    }
}
