<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * What the real public/service-worker.js precaches on install (tests/service-worker-install-harness.js):
 * every Encore entry's files - the live result entry included, so a referee's page works offline after any visit
 * (competition toolkit C2) - except the entries in PRECACHE_SKIP_ENTRIES (`display`: the competition timer screens and
 * stream overlays are for a few venue screens, never for every visitor - toolkit R §2.8).
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
            'compete' => ['js' => ['/build/runtime.1.js'], 'css' => ['/build/compete.7.css']],
            'display' => ['js' => ['/build/runtime.1.js', '/build/display.8.js'], 'css' => ['/build/display.9.css']],
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
            '/build/live_entry.6.js',
            '/build/compete.7.css',
        ], $result['added']);
    }

    public function testTheDisplayEntryIsNeverPrecached(): void
    {
        $added = array_merge(...$this->install(self::ENTRYPOINTS)['added']);

        self::assertNotContains('/build/display.8.js', $added);
        self::assertNotContains('/build/display.9.css', $added);
        self::assertContains('/build/runtime.1.js', $added, 'a file another entry needs too is still precached');
    }

    public function testTheSkipListNamesTheDisplayEntry(): void
    {
        $serviceWorker = (string) file_get_contents(__DIR__ . '/../public/service-worker.js');

        self::assertSame(1, preg_match("/^const PRECACHE_SKIP_ENTRIES = \\['display'\\];$/m", $serviceWorker));
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
