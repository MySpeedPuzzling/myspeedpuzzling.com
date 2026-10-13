<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The stopwatch page's controller (assets/controllers/stopwatch_controller.js, docs/features/navigation ADD-04) run for
 * real under Stimulus in jsdom (tests/stopwatch-controller-harness.mjs):
 * - an answer that arrives after the page was left (a Turbo visit keeps <html>) never marks the next page running -
 *   html[data-stopwatch-running] hides the phone tab bar - and starts nothing there (navigation review ADD F2);
 * - a Stop / Continue queued offline and replayed is applied to the page's own stopwatch, so the page and the top bar's
 *   pill agree (navigation review ADD Q2).
 */
final class StopwatchControllerScriptTest extends TestCase
{
    /** @var null|array<string, mixed> */
    private static null|array $results = null;

    public function testNoCaseThrew(): void
    {
        foreach ($this->results() as $case => $facts) {
            self::assertIsArray($facts, $case);
            self::assertArrayNotHasKey('threw', $facts, $case . ': ' . (is_string($facts['threw'] ?? null) ? $facts['threw'] : ''));
            self::assertSame([], $facts['errors'] ?? [], $case);
        }
    }

    public function testAStartAnsweredAfterThePageWasLeftLeavesTheNextPageAlone(): void
    {
        $facts = $this->case('start answered after leaving');

        // The tab bar steps aside at the tap, and comes back with the visit
        self::assertTrue($facts['atTap']);
        self::assertFalse($facts['afterLeave']);
        // The answer: no running mark on the next page, no frame loop, history entry or wake lock there
        self::assertFalse($facts['afterAnswer'], 'the next page keeps its tab bar');
        self::assertSame(0, $facts['framesStarted']);
        self::assertSame(0, $facts['replaceState']);
        self::assertSame(0, $facts['wakeLocks']);
        // The stopwatch did start: the device's pill state knows
        self::assertSame('running', $facts['pill']);
    }

    public function testAStartAnsweredOnThePageRunsAsBefore(): void
    {
        self::assertSame([
            'running' => true,
            'status' => 'running',
            'stopShown' => true,
            'framesWaiting' => 1,
            'replaceState' => 1,
            'wakeLocks' => 1,
            'pill' => 'running',
            'leaveName' => 'Leave the stopwatch – it keeps running',
            'errors' => [],
        ], $this->case('start answered on the page'));
    }

    public function testAStartRefusedAfterLeavingKeepsTheNextStopwatchsMark(): void
    {
        $facts = $this->case('start refused after leaving to a running stopwatch');

        self::assertTrue($facts['nextPageRunning']);
        self::assertTrue($facts['afterRefusal'], 'the left page\'s refusal does not take the next stopwatch\'s mark away');
    }

    public function testAWakeLockGrantedAfterLeavingIsReleased(): void
    {
        $facts = $this->case('wake lock granted after leaving');

        self::assertSame(1, $facts['requested']);
        self::assertSame([true], $facts['released']);
    }

    public function testAReplayedStopIsShownOnThePage(): void
    {
        $facts = $this->case('replayed stop of this stopwatch');

        self::assertSame([['url' => '/api/stopwatch/sw-1/pause', 'body' => ['shownTotalMs' => 61000]]], $facts['fetches']);
        self::assertSame('paused', $facts['status']);
        self::assertFalse($facts['running'], 'the tab bar comes back');
        self::assertFalse($facts['stop']);
        self::assertTrue($facts['resume']);
        self::assertTrue($facts['finish']);
        // What the stopwatch showed when Stop was tapped, not the server's running time it was rendered with
        self::assertSame('00:01:01', $facts['digits']);
        self::assertSame(0, $facts['framesWaiting'], 'the time stands still');
        self::assertNull($facts['leaveName'], 'Leave no longer says it keeps running');
        self::assertSame([], $facts['queue']);
        // The milestone bar starts again from 1:01: the first milestone (100 s) is ahead again, 61 % of the way
        self::assertSame('First 00:01:40', $facts['milestone']);
        self::assertSame('61%', $facts['milestoneWidth']);
    }

    public function testAReplayedContinueIsShownOnThePage(): void
    {
        $facts = $this->case('replayed continue of this stopwatch');

        self::assertSame(['/api/stopwatch/sw-1/resume'], $facts['fetches']);
        self::assertSame('running', $facts['status']);
        self::assertTrue($facts['running'], 'the tab bar steps aside');
        self::assertTrue($facts['stop']);
        self::assertFalse($facts['resume']);
        // 1:01 before, counting from the tap 5 s ago (a slow machine may have passed one more second)
        self::assertContains($facts['digits'], ['00:01:06', '00:01:07']);
        self::assertSame(1, $facts['framesWaiting']);
        self::assertSame(1, $facts['wakeLocks']);
        self::assertSame('Leave the stopwatch – it keeps running', $facts['leaveName']);
    }

    public function testAReplayedStopAndContinueEndRunningFromTheStoppedTime(): void
    {
        $facts = $this->case('replayed stop then continue');

        self::assertSame('running', $facts['status']);
        self::assertTrue($facts['running']);
        // 1:01 at the Stop + 10 s since the Continue - never the 10 minutes the page was rendered with
        self::assertContains($facts['digits'], ['00:01:11', '00:01:12']);
    }

    public function testAnotherStopwatchsReplayLeavesThePage(): void
    {
        self::assertSame([
            'fetches' => ['/api/stopwatch/sw-other/pause'],
            'status' => 'running',
            'running' => true,
            'stop' => true,
            'errors' => [],
        ], $this->case('replayed stop of another stopwatch'));
    }

    public function testARefusedReplayChangesNothingAndStaysQueued(): void
    {
        $facts = $this->case('replay refused');

        self::assertSame('running', $facts['status']);
        self::assertTrue($facts['running']);
        self::assertIsArray($facts['queue']);
        self::assertCount(1, $facts['queue']);
    }

    public function testAReplayAnsweredAfterLeavingLeavesTheNextPageAlone(): void
    {
        self::assertSame([
            'running' => false,
            'framesWaiting' => 0,
            'wakeLocks' => 0,
            'errors' => [],
        ], $this->case('replay answered after leaving'));
    }

    /**
     * @return array<string, mixed>
     */
    private function case(string $name): array
    {
        $facts = $this->results()[$name] ?? null;
        self::assertIsArray($facts, $name);
        self::assertArrayNotHasKey('threw', $facts, $name);

        /** @var array<string, mixed> $facts */
        return $facts;
    }

    /**
     * @return array<string, mixed>
     */
    private function results(): array
    {
        if (self::$results === null) {
            $node = new ExecutableFinder()->find('node');
            self::assertIsString($node, 'node is required to execute the controller - it is part of the base image');

            $process = new Process([$node, '--no-warnings', __DIR__ . '/stopwatch-controller-harness.mjs']);
            $process->mustRun();

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::$results = $decoded;
        }

        return self::$results;
    }
}
