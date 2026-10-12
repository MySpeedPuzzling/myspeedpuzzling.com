<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The installed PWA's pull-to-refresh (assets/pull_to_refresh.js, driven by controllers/pwa_lifecycle_controller.js)
 * calls preventDefault() on touchmove - whatever it takes, the browser cannot scroll. It used to take every downward
 * move while the page was at its top: inside an open modal (the page behind it does not scroll) a sheet scrolled down
 * could never be scrolled back up, and a sideways swipe over a table drifting a little downwards lost its scroll.
 * Runs the real module under node.
 */
final class PullToRefreshGestureTest extends TestCase
{
    /**
     * @param array<string, mixed> $case
     */
    #[DataProvider('touchStarts')]
    public function testWhereAPullMayStart(array $case, bool $allowed): void
    {
        self::assertSame($allowed, $this->gesture($case)['allowed']);
    }

    /**
     * @return iterable<string, array{array<string, mixed>, bool}>
     */
    public static function touchStarts(): iterable
    {
        yield 'page at its top, plain content' => [['path' => [['tag' => 'P'], ['tag' => 'MAIN']]], true];
        yield 'page scrolled' => [['scrollY' => 120, 'path' => [['tag' => 'P']]], false];
        yield 'a modal or sheet is open' => [['overlayOpen' => true, 'path' => [['tag' => 'P']]], false];
        // The reported bug: the add sheet's body scrolled down, the page behind it at its top
        yield 'inside a scrolled modal body' => [['path' => [['tag' => 'BUTTON'], ['scrollTop' => 340], ['tag' => 'DIV']]], false];
        yield 'inside a sideways scrolling table' => [['path' => [['tag' => 'TD'], ['tag' => 'TABLE'], ['scrollWidth' => 900, 'clientWidth' => 340, 'overflowX' => 'auto']]], false];
        yield 'inside a wide box that does not scroll' => [['path' => [['tag' => 'TD'], ['scrollWidth' => 900, 'clientWidth' => 340, 'overflowX' => 'hidden']]], true];
        yield 'inside an element that opts out' => [['path' => [['tag' => 'CANVAS'], ['attributes' => ['data-ptr-ignore']]]], false];
        yield 'inside a scroll box still at its top' => [['path' => [['tag' => 'LI'], ['scrollTop' => 0, 'overflowX' => 'hidden']]], true];
    }

    /**
     * What counts as a layer over the page (pull_to_refresh.js overlayOpen(), on a real DOM): a shown modal, the Menu
     * panel, the site search - but not the released Help sheet (review Q1, HELP-05: the page behind it is usable again,
     * so the page's pull-to-refresh is armed), except for a touch on the sheet itself
     */
    public function testAReleasedSheetLeavesThePageArmed(): void
    {
        $page = '<main><p id="page">Page</p></main>';
        $sheet = static fn (string $classes): string => '<div class="' . $classes . '"><div class="modal-dialog"><p id="sheet">Thanks</p></div></div>';
        $cases = [
            'plain page' => [['html' => $page, 'target' => '#page'], false],
            'a shown modal' => [['html' => $sheet('modal show') . $page, 'target' => '#page'], true],
            'a modal still on body.modal-open' => [['body' => 'modal-open', 'html' => $page, 'target' => '#page'], true],
            'the released sheet, a touch on the page' => [['html' => $sheet('modal show is-released') . $page, 'target' => '#page'], false],
            'the released sheet, a touch on the sheet' => [['html' => $sheet('modal show is-released') . $page, 'target' => '#sheet'], true],
            'the Menu panel (dialog[open])' => [['html' => '<dialog open><p>Menu</p></dialog>' . $page, 'target' => '#page'], true],
            'the site search' => [['body' => 'global-search-shown', 'html' => $page, 'target' => '#page'], true],
            'a closed modal' => [['html' => $sheet('modal') . $page, 'target' => '#page'], false],
        ];

        $node = new ExecutableFinder()->find('node');
        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/pull-to-refresh-harness.mjs']);
        $process->setInput(json_encode(['overlay' => array_column($cases, 0)], JSON_THROW_ON_ERROR));
        $process->mustRun();

        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($results);

        self::assertSame(
            array_combine(array_keys($cases), array_column($cases, 1)),
            array_combine(array_keys($cases), $results),
        );
    }

    public function testAClearlyDownwardMoveIsAPull(): void
    {
        $result = $this->gesture(['moves' => [[0, 3], [1, 9], [2, 40], [2, 120]]]);

        // The first pixels decide, nothing is taken from the browser before that
        self::assertSame([false, true, true, true], $result['prevented']);
        self::assertSame(120, $result['distance']);
    }

    /**
     * @param list<array{0: int, 1: int, 2?: array<string, bool|int>}> $moves
     */
    #[DataProvider('gesturesLeftToTheBrowser')]
    public function testNeverPreventsAGestureThatIsNotAPull(array $moves, int $touches = 1): void
    {
        $result = $this->gesture(['moves' => $moves, 'touches' => $touches]);

        self::assertSame(array_fill(0, count($moves), false), $result['prevented']);
        self::assertSame(0, $result['distance']);
    }

    /**
     * @return iterable<string, array{0: list<array{0: int, 1: int, 2?: array<string, bool|int>}>, 1?: int}>
     */
    public static function gesturesLeftToTheBrowser(): iterable
    {
        yield 'sideways swipe drifting downwards' => [[[-6, 1], [-14, 3], [-60, 12], [-140, 30]]];
        yield 'diagonal swipe' => [[[6, 6], [20, 20], [60, 60]]];
        yield 'upward scroll' => [[[0, -9], [0, -40]]];
        yield 'browser already scrolling' => [[[0, 10, ['cancelable' => false]], [0, 40]]];
        yield 'pinch with two fingers' => [[[0, 10], [0, 50]], 2];
    }

    public function testNothingIsPulledWhereThePullMayNotStart(): void
    {
        $result = $this->gesture([
            'path' => [['tag' => 'BUTTON'], ['scrollTop' => 340]],
            'moves' => [[0, 10], [0, 80], [0, 200]],
        ]);

        self::assertFalse($result['allowed']);
        self::assertSame([false, false, false], $result['prevented']);
    }

    public function testMovingBackAboveTheStartEndsThePull(): void
    {
        $result = $this->gesture(['moves' => [[0, 20], [0, 60], [0, -5], [0, 30]]]);

        self::assertSame([true, true, false, false], $result['prevented']);
        self::assertSame(0, $result['distance']);
    }

    /**
     * One touch: where it starts (`path`: the touched element and its ancestors, `scrollY`, `overlayOpen`) and how the
     * finger moves from there (`moves`: [dx, dy, page?]).
     *
     * @param array<string, mixed> $case
     * @return array{allowed: bool, prevented: list<bool>, distance: int|float}
     */
    private function gesture(array $case): array
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/pull-to-refresh-harness.mjs']);
        $process->setInput(json_encode([$case + ['moves' => []]], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var list<array{allowed: bool, prevented: list<bool>, distance: int|float}> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $results[0];
    }
}
