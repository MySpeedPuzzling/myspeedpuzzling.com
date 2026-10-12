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
            $actual[] = sprintf('%d: %s | %s', self::SECONDS[$index], $result['full'] ?? 'null', $result['compact'] ?? 'null');
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
     * @param list<array<string, mixed>> $cases
     * @return list<array<string, null|bool|int|string>>
     */
    private function runInNode(array $cases): array
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/stopwatch-elapsed-harness.mjs']);
        $process->setInput((string) json_encode($cases, JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var list<array<string, null|bool|int|string>> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $results;
    }
}
