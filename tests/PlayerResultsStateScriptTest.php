<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The Results page's address rules (docs/features/navigation PROF-05c): assets/player_results_state.js and
 * assets/restore_reload.js run under node (tests/player-results-state-harness.mjs).
 *
 * - A change of the filter form asks for the address PlayerResultsCriteria::toQuery() would write: the grammar's order,
 *   defaults and empty values dropped, never `page` and never the form's helpers; piece counts by the server's rule.
 * - Two addresses of the same list are one state (order, `[]` vs `[0]`, `+` vs `%20`, defaults, `return*`).
 * - Push for a discrete change, replace for typing / Show more / Show all, nothing for the same list
 *   and nothing inside the Hotwire Native app.
 * - The full-load Back path: one scroll key per list; the position restored only when a full load came back to it
 *   (app.js's restore reload or the browser's back/forward load), never after a visit, a snapshot restore or in the app.
 */
final class PlayerResultsStateScriptTest extends TestCase
{
    private const string PAGE = '/en/player-results/abc';

    /** @var null|array<string, mixed> */
    private static null|array $results = null;

    public function testNoCaseThrew(): void
    {
        foreach (self::results() as $case => $facts) {
            self::assertIsArray($facts, $case);
            self::assertArrayNotHasKey('threw', $facts, $case . ': ' . (is_string($facts['threw'] ?? null) ? $facts['threw'] : ''));
        }
    }

    public function testTheContractsNames(): void
    {
        self::assertSame([
            'owner' => 'results',
            'prefix' => 'msp:results-scroll:',
            'debounce' => 300,
            'restoreKey' => 'msp:restore-reload',
            // GEST-15 turns snapshots on
            'snapshots' => false,
        ], self::case('constants'));
    }

    public function testAFormChangeAsksForTheAddressTheServerWouldWrite(): void
    {
        $facts = self::case('url from form');

        // Every field of an untouched form is empty: the list's own address
        self::assertSame(self::PAGE, $facts['untouched']);
        self::assertSame(self::PAGE, $facts['defaults'], 'Defaults and the page are dropped');
        self::assertSame(self::PAGE, $facts['unitWithoutSpeed']);
        self::assertSame(
            self::PAGE . '?category=duo&view=every&sort=fastest&q=Night++sky&brand=Ravensburger&pieces=500&team=018d0000-0000-0000-0000-000000000001'
            . '&first=1&unboxed=1&relax=1&from=2026-01-01&to=2026-02-01&speed=12.5&speed_cmp=slower&speed_unit=min'
            . '&difficultyTiers%5B%5D=0&difficultyTiers%5B%5D=2',
            $facts['member'],
            'The grammar\'s order, the search trimmed, the tiers once each in order',
        );
        self::assertSame(100, $facts['longSearch']);
        self::assertSame(self::PAGE . '?first=1&unboxed=1', $facts['flags']);
    }

    public function testPieceCountsFollowTheServersRule(): void
    {
        self::assertSame([
            'chip' => '500',
            'any' => '',
            'typedNew' => '300-700',
            'customKept' => '300-700',
            'chipOverCustom' => '1000',
            'typedOverChip' => '100-',
            'swapped' => '100-900',
            'exact' => '750',
            'outOfRange' => '',
            'junk' => '',
            'clearedCustom' => '',
        ], self::case('pieces from form'));

        self::assertSame([
            'exact' => '500',
            'between' => '99-100',
            'atLeast' => '2000-',
            'atMost' => '-300',
            'legacyPlus' => '1001-',
            'legacySpace' => '1001-',
            'swapped' => '100-900',
            'zero' => null,
            'tooMany' => null,
            'junk' => null,
            'empty' => null,
        ], self::case('pieces grammar'));
    }

    public function testTwoAddressesOfTheSameListAreOneState(): void
    {
        self::assertSame([
            'order' => true,
            'brackets' => true,
            'spaces' => true,
            'defaults' => true,
            'returnIgnored' => true,
            'unknownIgnored' => true,
            'hashIgnored' => true,
            'otherSort' => false,
            'otherPage' => false,
            'otherPlayer' => false,
            // An explicit tab is the server's too (toQuery() writes a named category)
            'explicitSolo' => false,
            'tierOrder' => true,
            'query' => 'category=group&q=a&difficultyTiers%5B%5D=4&page=2',
        ], self::case('same state'));
    }

    public function testDiscreteChangesPushTypingAndShowMoreReplace(): void
    {
        self::assertSame([
            'discrete' => 'push',
            'submit' => 'push',
            'typing' => 'replace',
            'more' => 'replace',
            'all' => 'replace',
            // "Show from the start" above a page that leaves its first rows out (PROF review Q5): paging
            'start' => 'replace',
            'sameList' => 'none',
            'sameTyping' => 'none',
            'insideTheApp' => 'none',
            'takesOverOnTheWeb' => true,
            'takesOverInTheApp' => false,
        ], self::case('history action'));
    }

    /**
     * PROF review F4: a click within the search's 300 ms takes the typed text along as `q` (trimmed, cut like the field,
     * dropped when empty) and keeps the rest of its address
     */
    public function testAClickWithinTheTypingTakesTheTypedTextAlong(): void
    {
        self::assertSame([
            'tab' => 'https://msp.test' . self::PAGE . '?category=duo&q=night++sky',
            'replaced' => 'https://msp.test' . self::PAGE . '?q=new&sort=fastest',
            'paging' => 'https://msp.test' . self::PAGE . '?page=2&q=night',
            'emptied' => 'https://msp.test' . self::PAGE . '?brand=X',
            'long' => 100,
        ], self::case('typed query'));
    }

    /**
     * PROF review F2: Show more's answer is dropped unless it is still the list's and the page's it was asked for
     */
    public function testShowMoresAnswerAppliesOnlyToTheListItWasAskedFor(): void
    {
        self::assertSame([
            'same' => true,
            'sameListOtherForm' => true,
            'aborted' => false,
            'disconnected' => false,
            'footerGone' => false,
            'anotherListLoading' => false,
            'anotherListShown' => false,
            'anotherPage' => false,
        ], self::case('stream applies'));
    }

    public function testTheFormShowsTheListOfAnAddress(): void
    {
        $facts = self::case('form state');
        $empty = [
            'q' => '',
            'brand' => '',
            'team' => '',
            'pieces' => '',
            'piecesMin' => '',
            'piecesMax' => '',
            'piecesWas' => '',
            'first' => false,
            'unboxed' => false,
            'relax' => false,
            'from' => '',
            'to' => '',
            'speed' => '',
            'speedCmp' => 'faster',
            'speedUnit' => 'ppm',
            'difficultyTiers' => [],
        ];

        self::assertSame($empty, $facts['empty']);
        self::assertSame([
            'q' => 'Night  sky',
            'brand' => 'Ravensburger',
            'team' => '018d0000-0000-0000-0000-000000000001',
            'pieces' => '500',
            'piecesMin' => '',
            'piecesMax' => '',
            'piecesWas' => '500',
            'first' => true,
            'unboxed' => true,
            'relax' => true,
            'from' => '2026-01-01',
            'to' => '2026-02-01',
            'speed' => '12.5',
            'speedCmp' => 'slower',
            'speedUnit' => 'min',
            'difficultyTiers' => ['0', '2'],
        ], $facts['member']);
        // A custom range: no chip checked, the bounds shown, applied = the range
        self::assertSame(array_replace($empty, ['pieces' => null, 'piecesMin' => '300', 'piecesMax' => '700', 'piecesWas' => '300-700']), $facts['custom']);
        self::assertSame(array_replace($empty, ['pieces' => '2000-', 'piecesWas' => '2000-']), $facts['atLeast']);
        self::assertSame(array_replace($empty, ['pieces' => null, 'piecesMin' => '1500', 'piecesMax' => '1500', 'piecesWas' => '1500']), $facts['notOffered']);
        self::assertSame(['0', '3'], $facts['brackets']);
    }

    public function testTheSavedPositionIsRestoredOnlyAfterAFullLoadThatCameBack(): void
    {
        self::assertSame([
            'key' => 'msp:results-scroll:' . self::PAGE . '?sort=fastest&page=3',
            'keySame' => true,
            'keyBase' => 'msp:results-scroll:' . self::PAGE,
            'restore' => 'restore',
            'notBack' => 'none',
            'snapshot' => 'none',
            'app' => 'none',
            'nothingSaved' => 'none',
            'parsed' => ['y' => 4000.4, 'row' => 'result-01a-b', 'rowTop' => 210],
            'parsedJunk' => [null, null, null, null, null],
            'targetRow' => 4200,
            'targetNoRow' => 4000,
            'targetTop' => 0,
        ], self::case('scroll'));
    }

    public function testARestoreReloadIsReadOnceByTheNextDocument(): void
    {
        self::assertSame([
            'marked' => 'https://msp.test' . self::PAGE . '?page=3',
            'cameBack' => true,
            'cameBackWithHash' => true,
            // Read and removed by the next document - it never outlives one load
            'markLeft' => null,
            'otherUrl' => false,
            // A Turbo visit since the load: the page shown is another one
            'afterVisit' => false,
            'later' => false,
            // The browser's own way back (a back/forward load of the same URL) counts, a reload does not
            'backForward' => true,
            'backForwardElsewhere' => false,
            'reload' => false,
            'throwing' => false,
            // GEST-15
            'snapshot' => false,
        ], self::case('restore reload'));
    }

    /**
     * @return array<mixed>
     */
    private static function case(string $name): array
    {
        $facts = self::results()[$name] ?? null;
        self::assertIsArray($facts, $name . ' has no result in tests/player-results-state-harness.mjs');
        self::assertArrayNotHasKey('threw', $facts, $name);

        return $facts;
    }

    /**
     * @return array<string, mixed>
     */
    private static function results(): array
    {
        if (self::$results === null) {
            $node = new ExecutableFinder()->find('node');
            self::assertIsString($node, 'node is required to execute the module - it is part of the base image');

            $process = new Process([$node, __DIR__ . '/player-results-state-harness.mjs']);
            $process->mustRun();

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::$results = $decoded;
        }

        return self::$results;
    }
}
