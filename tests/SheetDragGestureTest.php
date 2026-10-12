<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Swipe down closes a bottom sheet (docs/features/navigation README §4.13 G2, requirement GEST-02): the rules of
 * assets/sheet_drag.js, run under node (tests/sheet-drag-harness.mjs). A drag starts on the handle / header, or on the
 * content scrolled to its top, after 8 px of mostly vertical downward movement - never in a textarea that scrolls or
 * holds a selection; the sheet closes when pulled > 96 px, past half its height or flicked > 0.6 px/ms (last ~100 ms)
 * after > 24 px, and springs back otherwise. The browser keeps every move until the drag has started.
 *
 * @phpstan-type Move array{0: int, 1: int, 2: int}
 * @phpstan-type Result array{allowed: bool, prevented: list<bool>, offsets: list<int|float>, offset: int|float, velocity: int|float, decision: string, state: string}
 */
final class SheetDragGestureTest extends TestCase
{
    /**
     * @param list<Move> $moves
     */
    #[DataProvider('releases')]
    public function testHowADragEnds(array $moves, int $height, string $decision, null|int $release = null): void
    {
        $case = ['moves' => $moves, 'height' => $height];

        if ($release !== null) {
            $case['release'] = $release;
        }

        self::assertSame($decision, $this->gesture([$case])[0]['decision']);
    }

    /**
     * @return iterable<string, array{0: list<Move>, 1: int, 2: string, 3?: int}>
     */
    public static function releases(): iterable
    {
        // README G2 / the plan's node cases (the finger's travel; the sheet follows it 8 px - the slop - behind)
        yield 'pulled 120 px' => [self::moves(120, 400), 600, 'close'];
        yield 'pulled 60 px slowly' => [self::moves(60, 1000), 600, 'spring'];
        yield 'flicked 40 px at 0.8 px/ms' => [self::moves(40, 50), 600, 'close'];
        yield 'flicked 20 px at 0.8 px/ms' => [self::moves(20, 25), 600, 'spring'];
        // Chrome holds back the touchmoves inside its own touch slop: the first one the page sees may come at ~15 px
        yield 'flicked 40 px, the first move seen at 16 px' => [[[0, 16, 20], [0, 24, 30], [0, 32, 40], [0, 40, 50]], 600, 'close'];
        yield '300 px sheet released at 160 px' => [self::moves(168, 2000), 300, 'close'];
        // Each rule on its own
        yield 'past half of a 150 px sheet, slowly (80 px)' => [self::moves(80, 2000), 150, 'close'];
        yield 'the same 80 px on a 300 px sheet' => [self::moves(80, 2000), 300, 'spring'];
        // The thresholds are the finger's travel, not the sheet's offset (Layers review F8: a 100 px pull closes)
        yield 'a 100 px pull, slowly' => [self::moves(100, 3000), 600, 'close'];
        yield 'just over 96 px, slowly' => [self::moves(97, 3000), 600, 'close'];
        yield '96 px exactly, slowly' => [self::moves(96, 3000), 600, 'spring'];
        yield 'a flick of 30 px' => [self::moves(30, 20), 600, 'close'];
        yield 'a flick that ends at 24 px' => [self::moves(24, 15), 600, 'spring'];
        // The speed of the last ~100 ms only: a fast pull that rested before the finger left
        yield 'fast 60 px, then 150 ms still' => [self::moves(60, 40), 600, 'spring', 190];
    }

    public function testTheBrowserKeepsEveryMoveUntilTheDragStarts(): void
    {
        $result = $this->gesture([['moves' => [[0, 3, 10], [1, 6, 20], [1, 9, 30], [2, 40, 40], [2, 130, 60]]]])[0];

        self::assertSame([false, false, true, true, true], $result['prevented']);
        // The sheet follows the finger 8 px (the slop) behind it
        self::assertSame([0, 0, 1, 32, 122], $result['offsets']);
        self::assertSame('close', $result['decision']);
    }

    public function testTheSheetNeverGoesAboveItsPlace(): void
    {
        $result = $this->gesture([['moves' => [[0, 10, 10], [0, 50, 200], [0, -30, 400]], 'release' => 600]])[0];

        self::assertSame([2, 42, 0], $result['offsets']);
        self::assertSame('spring', $result['decision']);
    }

    /** Layers review F7: a mouse released off the handle inside the slop never drags the sheet on a later hover */
    public function testAMouseMovingWithNoButtonHeldEndsTheGesture(): void
    {
        $mouse = ['pointerType' => 'mouse', 'buttons' => 1];
        $hover = ['pointerType' => 'mouse', 'buttons' => 0];
        $result = $this->gesture([['moves' => [[0, 3, 10, $mouse], [0, 4, 20, $hover], [0, 40, 40, $hover], [0, 140, 80, $hover]]]])[0];

        self::assertSame([false], $result['prevented']);
        self::assertSame('none', $result['decision']);
        self::assertSame('idle', $result['state']);

        // A finger (touch / pen report buttons 0 or 1 on their own) is never taken for a released mouse
        $touch = ['pointerType' => 'touch', 'buttons' => 0];
        $dragged = $this->gesture([['moves' => [[0, 10, 10, $touch], [0, 60, 40, $touch], [0, 130, 80, $touch]]]])[0];
        self::assertSame('close', $dragged['decision']);
    }

    public function testACancelledDragSpringsBack(): void
    {
        $result = $this->gesture([['moves' => [[0, 10, 10], [0, 200, 30]], 'cancel' => true]])[0];

        self::assertSame('spring', $result['decision']);
        self::assertSame('idle', $result['state']);
    }

    /**
     * @param list<Move> $moves
     */
    #[DataProvider('notDrags')]
    public function testGesturesThatAreNoDrag(array $moves): void
    {
        $result = $this->gesture([['moves' => $moves]])[0];

        self::assertSame(array_fill(0, count($moves), false), $result['prevented']);
        self::assertSame('none', $result['decision']);
    }

    /**
     * @return iterable<string, array{list<Move>}>
     */
    public static function notDrags(): iterable
    {
        yield 'sideways' => [[[-6, 1, 10], [-14, 3, 20], [-60, 12, 40], [-140, 30, 80]]];
        yield 'upward (scrolls the content)' => [[[0, -9, 10], [0, -40, 30]]];
        yield 'diagonal' => [[[8, 10, 10], [30, 30, 30], [60, 70, 60]]];
        yield 'upward first, then down' => [[[0, -9, 10], [0, 40, 60], [0, 160, 120]]];
        yield 'a tap with a little jitter' => [[[2, 3, 10], [-1, 5, 40]]];
    }

    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('starts')]
    public function testWhereADragMayStart(array $case, bool $allowed): void
    {
        $result = $this->gesture([$case + ['moves' => self::moves(120, 400)]])[0];

        self::assertSame($allowed, $result['allowed']);
        self::assertSame($allowed ? 'close' : 'none', $result['decision']);
        // Never a drag - the browser keeps the whole gesture (the content scrolls, the textarea scrolls or selects)
        if (!$allowed) {
            self::assertNotContains(true, $result['prevented']);
        }
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function starts(): iterable
    {
        // The handle / header area (pointer events, captured once the drag starts)
        yield 'handle: the title' => [['zone' => 'handle', 'path' => [['tag' => 'H5'], ['tag' => 'DIV'], ['tag' => 'DIV']]], true];
        yield 'handle: the handle bar' => [['zone' => 'handle', 'path' => [['tag' => 'SPAN'], ['tag' => 'DIV']]], true];
        yield 'handle: the × stays a tap' => [['zone' => 'handle', 'path' => [['tag' => 'BUTTON'], ['tag' => 'DIV'], ['tag' => 'DIV']]], false];
        yield 'handle: a link stays a tap' => [['zone' => 'handle', 'path' => [['tag' => 'SPAN'], ['tag' => 'A'], ['tag' => 'DIV']]], false];
        // The content (touch events) at its top
        yield 'body at its top: text' => [['path' => [['tag' => 'P'], ['tag' => 'DIV']]], true];
        yield 'body at its top: a button (the medal) - a drag only after the slop' => [['path' => [['tag' => 'SPAN'], ['tag' => 'BUTTON'], ['tag' => 'DIV']]], true];
        yield 'body scrolled' => [['path' => [['tag' => 'P'], ['tag' => 'DIV', 'scrollTop' => 120]]], false];
        yield 'a scroll box inside the body, scrolled' => [['path' => [['tag' => 'LI'], ['tag' => 'UL', 'scrollTop' => 40], ['tag' => 'DIV']]], false];
        yield 'a textarea that scrolls' => [['path' => [['tag' => 'TEXTAREA', 'scrollHeight' => 300, 'clientHeight' => 100], ['tag' => 'FORM'], ['tag' => 'DIV']]], false];
        yield 'a textarea holding a selection' => [['path' => [['tag' => 'TEXTAREA', 'selection' => [2, 7]], ['tag' => 'FORM'], ['tag' => 'DIV']]], false];
        yield 'a textarea that neither scrolls nor holds a selection' => [['path' => [['tag' => 'TEXTAREA'], ['tag' => 'FORM'], ['tag' => 'DIV']]], true];
        yield 'an input' => [['path' => [['tag' => 'INPUT'], ['tag' => 'DIV']]], false];
        yield 'a select' => [['path' => [['tag' => 'SELECT'], ['tag' => 'DIV']]], false];
        yield 'contenteditable' => [['path' => [['tag' => 'P', 'editable' => true], ['tag' => 'DIV']]], false];
        yield 'outside the sheet\'s area' => [['outside' => true, 'path' => [['tag' => 'P'], ['tag' => 'DIV']]], false];
    }

    /**
     * A straight pull from (0, 0) at t = 0 to (0, $distance) at t = $duration, a move every 10 ms (at least 4 moves)
     *
     * @return list<Move>
     */
    private static function moves(int $distance, int $duration): array
    {
        $steps = max(4, intdiv($duration, 10));
        $moves = [];

        for ($i = 1; $i <= $steps; $i++) {
            $moves[] = [0, (int) round($distance * $i / $steps), (int) round($duration * $i / $steps)];
        }

        return $moves;
    }

    /**
     * @param list<array<string, mixed>> $cases
     * @return list<Result>
     */
    private function gesture(array $cases): array
    {
        $node = new ExecutableFinder()->find('node');
        self::assertIsString($node, 'node is required to execute the gesture module - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/sheet-drag-harness.mjs']);
        $process->setInput(json_encode($cases, JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var list<Result> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $results;
    }
}
