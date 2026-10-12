<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Results\RunningStopwatch;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The running-stopwatch pill (docs/features/navigation ADD-03) shows digits the server rendered
 * (RunningStopwatch::format()) and then ticks on in the browser (assets/stopwatch_elapsed.js) - both must write every
 * number the same, or the pill changes on its first tick. Runs the real module under node: the two formats, the
 * elapsed seconds and the next tick, and when a pill hides because this device stopped its stopwatch after the render.
 */
final class StopwatchElapsedFormatTest extends TestCase
{
    // Each format's edges: 9:59 → 10:00 (the compact icon), 99:59 → 100:00 and 999:59 → 1000:00 (one more digit), hours
    private const array SECONDS = [
        0, 1, 9, 10, 59, 60, 61, 599, 600, 601, 3599, 3600, 3601, 5999, 6000, 35999, 36000, 59999, 60000,
        86399, 86400, 359999, 360000, 3_600_000,
    ];

    public function testTheBrowserWritesEveryNumberLikeTheServer(): void
    {
        $cases = array_map(static fn (int $seconds): array => ['kind' => 'format', 'seconds' => $seconds], self::SECONDS);

        $expected = array_map(static fn (int $seconds): string => sprintf(
            '%d: %s | %s',
            $seconds,
            RunningStopwatch::format($seconds, false),
            RunningStopwatch::format($seconds, true),
        ), self::SECONDS);

        $actual = [];

        foreach ($this->runInNode($cases) as $index => $result) {
            $full = $result['full'] ?? 'null';
            $compact = $result['compact'] ?? 'null';
            self::assertIsString($full);
            self::assertIsString($compact);
            $actual[] = sprintf('%d: %s | %s', self::SECONDS[$index], $full, $compact);
        }

        self::assertSame($expected, $actual);
    }

    public function testTheEdgesOfEachFormat(): void
    {
        $cases = array_map(static fn (int|float $seconds): array => ['kind' => 'format', 'seconds' => $seconds], [599, 600, 5999, 6000, 59999, 60000, 86399, -3, 59.9]);

        self::assertSame([
            ['full' => '0:09:59', 'compact' => '9:59'],
            ['full' => '0:10:00', 'compact' => '10:00'],
            ['full' => '1:39:59', 'compact' => '99:59'],
            ['full' => '1:40:00', 'compact' => '100:00'],
            ['full' => '16:39:59', 'compact' => '999:59'],
            ['full' => '16:40:00', 'compact' => '1000:00'],
            ['full' => '23:59:59', 'compact' => '1439:59'],
            // Never negative, whole seconds only
            ['full' => '0:00:00', 'compact' => '0:00'],
            ['full' => '0:00:59', 'compact' => '0:59'],
        ], $this->runInNode($cases));
    }

    public function testElapsedSecondsAndTheNextTick(): void
    {
        $lapStarted = 1_760_000_000;

        self::assertSame([
            // 900 closed + 600.4 running: the next change when the running lap reaches 601
            ['seconds' => 1500, 'nextChangeAt' => $lapStarted + 601],
            ['seconds' => 1501, 'nextChangeAt' => $lapStarted + 602],
            // The server's clock behind the lap start (a device clock offset gone wrong): counted from 0, wakes at +1
            ['seconds' => 900, 'nextChangeAt' => $lapStarted + 1],
        ], $this->runInNode([
            ['kind' => 'elapsed', 'lapStarted' => $lapStarted, 'closedSeconds' => 900, 'serverNow' => $lapStarted + 600.4],
            ['kind' => 'elapsed', 'lapStarted' => $lapStarted, 'closedSeconds' => 900, 'serverNow' => $lapStarted + 601],
            ['kind' => 'elapsed', 'lapStarted' => $lapStarted, 'closedSeconds' => 900, 'serverNow' => $lapStarted - 5],
        ]));
    }

    /**
     * Laps to the millisecond (since 2026-10-12): the total is cut to whole seconds once, never per lap - 899.9 closed
     * + 600.2 running is 1500 s, as the stopwatch page shows it (cut per lap it was 899 + 600 = 1499)
     */
    public function testMillisecondLapsAreCutOnceAtTheEnd(): void
    {
        $lapStarted = 1_760_000_000.25;

        self::assertSame([
            ['seconds' => 1499, 'nextChangeAt' => 1_760_000_600.35],
            ['seconds' => 1500, 'nextChangeAt' => 1_760_000_601.35],
            ['seconds' => 1500, 'nextChangeAt' => 1_760_000_601.35],
            // Before the lap starts: the closed laps only, the next change when the lap has run up to the next second
            ['seconds' => 899, 'nextChangeAt' => 1_760_000_000.35],
        ], $this->runInNode([
            ['kind' => 'elapsed', 'lapStarted' => $lapStarted, 'closedSeconds' => 899.9, 'serverNow' => $lapStarted + 600.0],
            ['kind' => 'elapsed', 'lapStarted' => $lapStarted, 'closedSeconds' => 899.9, 'serverNow' => $lapStarted + 600.1],
            ['kind' => 'elapsed', 'lapStarted' => $lapStarted, 'closedSeconds' => 899.9, 'serverNow' => $lapStarted + 600.2],
            ['kind' => 'elapsed', 'lapStarted' => $lapStarted, 'closedSeconds' => 899.9, 'serverNow' => $lapStarted - 5],
        ]));
    }

    /**
     * The single record of the first release (`{id, status, at}`) - still read, so a value written before the map works.
     */
    public function testThePillHidesOnlyWhenThisDeviceStoppedItsStopwatchAfterTheRender(): void
    {
        $id = '018d000d-0000-0000-0000-00000000aa01';
        $rendered = 1_760_000_000;
        // This device's clock runs 2 s behind the server: Date.now() + 2000 = the server's time
        $offset = 2000;
        $state = static fn (string $status, int $atClientMs, string $stopwatchId = '018d000d-0000-0000-0000-00000000aa01'): string => (string) json_encode(['id' => $stopwatchId, 'status' => $status, 'at' => $atClientMs]);
        $afterRender = $rendered * 1000 + 5000 - $offset;
        $beforeRender = $rendered * 1000 - 60_000 - $offset;

        $cases = [
            // Paused, reset or finished here after the page was rendered: hide
            ['kind' => 'stopped', 'stored' => $state('paused', $afterRender), 'stopwatchId' => $id, 'rendered' => $rendered, 'offsetMs' => $offset],
            ['kind' => 'stopped', 'stored' => $state('reset', $afterRender), 'stopwatchId' => $id, 'rendered' => $rendered, 'offsetMs' => $offset],
            ['kind' => 'stopped', 'stored' => $state('finished', $afterRender), 'stopwatchId' => $id, 'rendered' => $rendered, 'offsetMs' => $offset],
            // Paused before the render: the server already knew better (it was resumed since, elsewhere)
            ['kind' => 'stopped', 'stored' => $state('paused', $beforeRender), 'stopwatchId' => $id, 'rendered' => $rendered, 'offsetMs' => $offset],
            // Resumed: keeps running
            ['kind' => 'stopped', 'stored' => $state('running', $afterRender), 'stopwatchId' => $id, 'rendered' => $rendered, 'offsetMs' => $offset],
            // Another stopwatch
            ['kind' => 'stopped', 'stored' => $state('paused', $afterRender, '018d000d-0000-0000-0000-00000000aa02'), 'stopwatchId' => $id, 'rendered' => $rendered, 'offsetMs' => $offset],
            // Nothing stored, or something else under the key
            ['kind' => 'stopped', 'stored' => null, 'stopwatchId' => $id, 'rendered' => $rendered, 'offsetMs' => $offset],
            ['kind' => 'stopped', 'stored' => '{"id":', 'stopwatchId' => $id, 'rendered' => $rendered, 'offsetMs' => $offset],
            ['kind' => 'stopped', 'stored' => '{"id": "x"}', 'stopwatchId' => $id, 'rendered' => $rendered, 'offsetMs' => $offset],
        ];

        self::assertSame(
            [true, true, true, false, false, false, false, false, false],
            array_map(static fn (array $result): mixed => $result['stopped'] ?? null, $this->runInNode($cases)),
        );
    }

    /**
     * One record per stopwatch (review F6): pausing A and then starting or deleting B keeps A's pause; the old single
     * record becomes the map's first entry; the 10 newest stopwatches are kept.
     */
    public function testEveryStopwatchKeepsItsOwnLastState(): void
    {
        $a = '018d000d-0000-0000-0000-00000000aa01';
        $b = '018d000d-0000-0000-0000-00000000aa02';
        $old = (string) json_encode(['id' => $a, 'status' => 'paused', 'at' => 1000]);

        $results = $this->runInNode([
            // A paused, then B started and deleted (deleteOther)
            ['kind' => 'remember', 'stored' => null, 'writes' => [[$a, 'paused', 1000, 600_000], [$b, 'running', 2000, 0], [$b, 'reset', 3000, null]]],
            // Written by the release before the map
            ['kind' => 'remember', 'stored' => $old, 'writes' => [[$b, 'running', 2000, 0]]],
            // Eleven stopwatches: the oldest goes
            ['kind' => 'remember', 'stored' => null, 'writes' => array_map(
                static fn (int $i): array => [sprintf('018d000d-0000-0000-0000-0000000001%02d', $i), 'paused', 1000 + $i, null],
                range(1, 11),
            )],
            // Damaged entries are dropped, the rest stays
            ['kind' => 'remember', 'stored' => (string) json_encode([$a => ['status' => 'paused', 'at' => 1000], $b => ['status' => 7]]), 'writes' => []],
        ]);

        self::assertSame([
            $a => ['status' => 'paused', 'at' => 1000, 'elapsed' => 600_000],
            $b => ['status' => 'reset', 'at' => 3000],
        ], self::sorted($results[0]['states']));
        self::assertSame([
            $a => ['status' => 'paused', 'at' => 1000],
            $b => ['status' => 'running', 'at' => 2000, 'elapsed' => 0],
        ], self::sorted($results[1]['states']));
        self::assertIsArray($results[2]['states']);
        self::assertCount(10, $results[2]['states']);
        self::assertArrayNotHasKey('018d000d-0000-0000-0000-000000000101', $results[2]['states']);
        self::assertArrayHasKey('018d000d-0000-0000-0000-000000000111', $results[2]['states']);
        self::assertSame([$a => ['status' => 'paused', 'at' => 1000]], $results[3]['states']);
    }

    /**
     * A hidden pill keeps listening (review F6): a newer "running" for its stopwatch shows it again, counting from the
     * elapsed time the resume recorded - the paused minutes left out; another stopwatch's records change nothing.
     */
    public function testAHiddenPillShowsAgainOnAResumeAndLeavesThePauseOut(): void
    {
        $a = '018d000d-0000-0000-0000-00000000aa01';
        $b = '018d000d-0000-0000-0000-00000000aa02';
        $rendered = 1_760_000_000;
        $offset = 2000;
        // Rendered 10 minutes into the stopwatch; on this device's clock the render was at $renderedMs
        $lapStarted = $rendered - 600;
        $renderedMs = $rendered * 1000 - $offset;
        $map = static fn (array $states): string => (string) json_encode($states);
        $pill = static fn (string $stored, int $now): array => ['kind' => 'pill', 'stored' => $stored, 'stopwatchId' => $a, 'rendered' => $rendered, 'offsetMs' => $offset, 'lapStarted' => $lapStarted, 'closedSeconds' => 0, 'now' => $now];

        $paused = [$a => ['status' => 'paused', 'at' => $renderedMs + 5000, 'elapsed' => 605_000]];
        // Resumed 5 minutes after the pause: still 605 s, counting on from the resume
        $resumed = [$a => ['status' => 'running', 'at' => $renderedMs + 305_000, 'elapsed' => 605_000]];

        self::assertSame([
            ['hidden' => true, 'seconds' => null],
            ['hidden' => false, 'seconds' => 615],
            // Pausing A then starting B: A stays hidden
            ['hidden' => true, 'seconds' => null],
            // A "running" from before the render, or without the elapsed time: the render's own numbers
            ['hidden' => false, 'seconds' => 610],
            ['hidden' => false, 'seconds' => 610],
        ], $this->runInNode([
            $pill($map($paused), $renderedMs + 6000),
            $pill($map($resumed), $renderedMs + 315_000),
            $pill($map($paused + [$b => ['status' => 'running', 'at' => $renderedMs + 7000, 'elapsed' => 0]]), $renderedMs + 8000),
            $pill($map([$a => ['status' => 'running', 'at' => $renderedMs - 60_000, 'elapsed' => 1000]]), $renderedMs + 10_000),
            $pill($map([$a => ['status' => 'running', 'at' => $renderedMs + 5000]]), $renderedMs + 10_000),
        ]));
    }

    /**
     * The clock offset is measured when the page's HTML arrived (review F5): a pill connecting 3 s later - the lazy chunk
     * still downloading - shows the render's time + 3 s. The receipt is used only for its own page (the stopwatch read
     * up to 5 s before the page's msp-rendered-at, or 1 s after it); otherwise the pill measures at connect, as before.
     */
    public function testTheOffsetIsMeasuredWhenTheHtmlArrived(): void
    {
        $rendered = 1_760_000_000;
        // This device's clock runs 2 s behind the server; the HTML arrived 400 ms after the render
        $arrived = $rendered * 1000 - 2000 + 400;
        $connected = $arrived + 3000;
        $case = static fn (null|array $receipt): array => ['kind' => 'offset', 'rendered' => $rendered, 'receipt' => $receipt, 'connectedAt' => $connected, 'lapStarted' => $rendered - 600, 'closedSeconds' => 60];

        $results = $this->runInNode([
            $case(['renderedAt' => $rendered, 'at' => $arrived]),
            $case(['renderedAt' => $rendered + 1, 'at' => $arrived]),
            $case(['renderedAt' => $rendered - 1, 'at' => $arrived]),
            // Another page's receipt (a later render, or a render long before) - measured at connect
            $case(['renderedAt' => $rendered + 30, 'at' => $arrived]),
            $case(['renderedAt' => $rendered - 2, 'at' => $arrived]),
            $case(null),
        ]);

        self::assertSame(
            [663, 663, 663, 660, 660, 660],
            array_map(static fn (array $result): mixed => $result['secondsAtConnect'] ?? null, $results),
        );
        self::assertSame(1600, $results[0]['offsetMs']);
    }

    public function testAFullPageLoadsReceiptIsItsFirstByte(): void
    {
        $origin = 1_760_000_000_000;

        self::assertSame([
            ['receipt' => 1_760_000_000_120],
            // Back/forward: the HTML may come from the cache - no receipt
            ['receipt' => null],
            ['receipt' => null],
            ['receipt' => null],
        ], $this->runInNode([
            ['kind' => 'navigationReceipt', 'entry' => ['type' => 'navigate', 'responseStart' => 120], 'timeOrigin' => $origin],
            ['kind' => 'navigationReceipt', 'entry' => ['type' => 'back_forward', 'responseStart' => 120], 'timeOrigin' => $origin],
            ['kind' => 'navigationReceipt', 'entry' => ['type' => 'navigate', 'responseStart' => 0], 'timeOrigin' => $origin],
            ['kind' => 'navigationReceipt', 'entry' => null, 'timeOrigin' => $origin],
        ]));
    }

    /**
     * @param mixed $states
     * @return array<mixed>
     */
    private static function sorted(mixed $states): array
    {
        self::assertIsArray($states);
        ksort($states);

        return $states;
    }

    /**
     * @param list<array<string, mixed>> $cases
     * @return list<array<string, mixed>>
     */
    private function runInNode(array $cases): array
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/stopwatch-elapsed-harness.mjs']);
        $process->setInput((string) json_encode($cases, JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var list<array<string, mixed>> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $results;
    }
}
