<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Back measurement (docs/features/navigation README §4.13 G9, requirement GEST-08): assets/back_measurement.js under node
 * (tests/back-measurement-harness.mjs). One line per Back / Forward with how the page came back - snapshot (ms from the
 * popstate to turbo:load), reload (instant_back's full load, measured by the next document from the sessionStorage
 * mark; or a back/forward load the browser made by itself), bfcache (ms from the restoration entry), bfcache-reload -
 * the direction and whether the browser animated it; each line also an `msp:back` event. A layer's Back is no line
 * (layer_history.js stops its popstate first); a normal load and a visit are none either; blocked storage never throws.
 *
 * The direction holds whatever runs first: Chromium calls a window's popstate listeners in the order they were added,
 * and Turbo's - added when Turbo starts - decides the restore before this module's listener sees the popstate (measured
 * in the P0-03 browser check: every Back after a plain Turbo visit was logged `unknown`). It comes from the Navigation
 * API's navigate event, else from Turbo's restoration index; a traversal's activation entry that Turbo replaced at start
 * (index -1) gives way to the current entry. `unknown` only where the browser cannot know it.
 */
final class BackMeasurementScriptTest extends TestCase
{
    /** @var null|array<string, mixed> */
    private static null|array $results = null;

    public function testNoCaseThrew(): void
    {
        foreach (self::results() as $case => $result) {
            self::assertFalse(is_array($result) && array_key_exists('threw', $result) && $result['threw'] !== false, $case . ': ' . json_encode($result));
        }
    }

    public function testTheContract(): void
    {
        self::assertSame([
            'BACK_PENDING_KEY' => 'msp:back-pending',
            'BACK_EVENT' => 'msp:back',
            'KINDS' => ['snapshot', 'reload', 'bfcache', 'bfcache-reload'],
        ], self::results()['contract']);
    }

    public function testThePayloadOfEachKind(): void
    {
        self::assertSame([
            'snapshot' => ['restore' => 'snapshot', 'ms' => 42, 'dir' => 'back', 'uaTransition' => false],
            'reload' => ['restore' => 'reload', 'ms' => 1200, 'dir' => 'forward', 'uaTransition' => null],
            'bfcache' => ['restore' => 'bfcache', 'ms' => 0, 'dir' => 'unknown', 'uaTransition' => null],
            'bfcacheReload' => ['restore' => 'bfcache-reload', 'ms' => null, 'dir' => 'unknown', 'uaTransition' => null],
            'unknown' => null,
            'nothing' => null,
        ], self::results()['payloads']);
    }

    public function testASnapshotRestoreIsOneLineAtItsTurboLoad(): void
    {
        $line = ['restore' => 'snapshot', 'ms' => 38, 'dir' => 'back', 'uaTransition' => false];

        self::assertSame(['lines' => [$line], 'events' => [$line]], self::results()['snapshot restore']);
        self::assertSame(['lines' => []], self::results()['a layer closed by Back is no line']);
    }

    public function testAFullLoadIsMeasuredByTheNextDocumentOnce(): void
    {
        self::assertSame([
            'pending' => ['kind' => 'reload', 'dir' => 'back', 'uaTransition' => true],
            'lines' => [['restore' => 'reload', 'ms' => 850, 'dir' => 'back', 'uaTransition' => true]],
            'pendingLeft' => null,
        ], self::results()['full-load restore']);
    }

    public function testTheBackForwardCache(): void
    {
        self::assertSame([
            'kept' => [['restore' => 'bfcache', 'ms' => 4, 'dir' => 'back', 'uaTransition' => null]],
            'reloaded' => [['restore' => 'bfcache-reload', 'ms' => 640, 'dir' => 'forward', 'uaTransition' => null]],
        ], self::results()['back/forward cache']);
    }

    public function testABackForwardLoadWithoutUsIsAReload(): void
    {
        self::assertSame([
            'traverse' => [['restore' => 'reload', 'ms' => 730, 'dir' => 'back', 'uaTransition' => null]],
            'timingOnly' => [['restore' => 'reload', 'ms' => 500, 'dir' => 'unknown', 'uaTransition' => null]],
            'normal' => [],
        ], self::results()['a back/forward load without us']);
    }

    public function testTheDirectionHoldsWhenTurboDecidesTheRestoreFirst(): void
    {
        self::assertSame([
            'snapshotBack' => [['restore' => 'snapshot', 'ms' => 40, 'dir' => 'back', 'uaTransition' => true]],
            'snapshotForward' => [['restore' => 'snapshot', 'ms' => 40, 'dir' => 'forward', 'uaTransition' => false]],
            'reloadBack' => ['kind' => 'reload', 'dir' => 'back', 'uaTransition' => true],
            'reloadForward' => ['kind' => 'reload', 'dir' => 'forward', 'uaTransition' => null],
        ], self::results()['Turbo decides the restore before our popstate listener']);
    }

    public function testWithoutTheNavigationApiTurbosRestorationIndexTellsTheDirection(): void
    {
        self::assertSame([
            'snapshots' => [
                ['restore' => 'snapshot', 'ms' => 30, 'dir' => 'back', 'uaTransition' => false],
                ['restore' => 'snapshot', 'ms' => 20, 'dir' => 'forward', 'uaTransition' => null],
            ],
            'reloadBack' => ['kind' => 'reload', 'dir' => 'back', 'uaTransition' => true],
            'reloadForward' => ['kind' => 'reload', 'dir' => 'forward', 'uaTransition' => null],
        ], self::results()["without the Navigation API: Turbo's restoration index"]);
    }

    public function testAPopLayerHistoryStopsIsMeasuredFromItsOwnTraversal(): void
    {
        // Not the page owner's earlier pop (its direction and its start 5 s before)
        self::assertSame([
            'navigationApi' => [['restore' => 'snapshot', 'ms' => 45, 'dir' => 'back', 'uaTransition' => true]],
            'turboIndex' => [['restore' => 'snapshot', 'ms' => 45, 'dir' => 'back', 'uaTransition' => null]],
        ], self::results()['a pop layer_history stops and restores itself']);
    }

    public function testAnActivationEntryTurboReplacedGivesWayToTheCurrentEntry(): void
    {
        self::assertSame([
            'load' => [['restore' => 'reload', 'ms' => 610, 'dir' => 'back', 'uaTransition' => null]],
            'forward' => [['restore' => 'reload', 'ms' => 420, 'dir' => 'forward', 'uaTransition' => null]],
            'bfcache' => [['restore' => 'bfcache', 'ms' => 2, 'dir' => 'back', 'uaTransition' => null]],
            'bfcacheReload' => 'forward',
        ], self::results()['a traversal whose activation entry Turbo replaced']);
    }

    public function testADirectionNobodyCanKnowStaysUnknown(): void
    {
        self::assertSame([
            'snapshot' => [['restore' => 'snapshot', 'ms' => 10, 'dir' => 'unknown', 'uaTransition' => null]],
            'bfcache' => [['restore' => 'bfcache', 'ms' => 0, 'dir' => 'unknown', 'uaTransition' => null]],
        ], self::results()['a direction nobody can know']);
    }

    public function testBlockedStorageNeverThrows(): void
    {
        self::assertSame(['lines' => [], 'threw' => false], self::results()['blocked storage']);
    }

    /**
     * @return array<string, mixed>
     */
    private static function results(): array
    {
        if (self::$results !== null) {
            return self::$results;
        }

        $node = new ExecutableFinder()->find('node');
        self::assertIsString($node, 'node is required to execute the module - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/back-measurement-harness.mjs']);
        $process->mustRun();

        /** @var array<string, mixed> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return self::$results = $results;
    }
}
