<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Back closes the top layer (assets/layer_history.js, docs/features/navigation GEST-01, implementation plan §3.4):
 * (a) one pause of Turbo's history for every owner, (b) one same-URL entry per layer and a swap instead of
 * back() + push, (c) ×/Esc/Back close through one history.back(), (d) leaving from a layer replaces its entry -
 * history reads [A, B], never [A, A', B], (f) nothing pushed inside the native app; plus the page owners of PROF-05c
 * (review F3), the form order of Turbo 8.0.23 (review F4) and the fallbacks when Turbo's internals are missing (F5).
 *
 * Runs the real module under node in a fake browser written after Turbo 8.0.23's own history code
 * (tests/layer-history-harness.mjs); the internals it relies on are pinned against the real dist by
 * TurboContractScriptTest.
 */
final class LayerHistoryScriptTest extends TestCase
{
    /** @var null|array<string, mixed> */
    private static null|array $results = null;

    public function testNoCaseThrew(): void
    {
        foreach ($this->results() as $case => $facts) {
            self::assertIsArray($facts, $case);
            self::assertArrayNotHasKey('threw', $facts, $case . ': ' . (is_string($facts['threw'] ?? null) ? $facts['threw'] : ''));
        }
    }

    public function testTheFirstOwnerResumingDoesNotRestartTurbosHistory(): void
    {
        self::assertSame([
            'stops' => 1,
            'startedAfterTheLayerClosed' => false,
            'startedAfterResults' => true,
            'closes' => ['menu:back'],
        ], $this->case('two owners'));
    }

    public function testASecondLayerTakesTheEntryOverWithoutBack(): void
    {
        $facts = $this->case('swap');

        self::assertSame(2, $facts['lengthBefore']);
        self::assertSame(2, $facts['lengthAfter']);
        self::assertSame(['push', 'replace'], $facts['calls']);
        self::assertSame(['mspLayer' => 'modal'], $facts['state']);
        self::assertSame('modal', $facts['current']);
        self::assertSame(['menu:swap'], $facts['closes']);
    }

    public function testAPopstateClosesTheLayerAndResumesTurboWithThePagesIdentity(): void
    {
        $facts = $this->case('popstate closes');

        // Opening: one same-URL entry, Turbo paused
        self::assertSame(['length' => 4, 'index' => 3, 'state' => ['mspLayer' => 'menu'], 'turboStarted' => false], $facts['opened']);
        self::assertSame(['menu:back'], $facts['closes']);
        self::assertFalse($facts['isOpen']);
        self::assertSame(2, $facts['index']);
        self::assertSame('/en/faq', $facts['url']);
        // Turbo listens again and its entry keeps the identifier and index it had (its saved scroll stays)
        self::assertTrue($facts['turboStarted']);
        self::assertSame('page-2', $facts['restorationIdentifier']);
        self::assertSame(2, $facts['currentIndex']);
        self::assertSame(['turbo' => ['restorationIdentifier' => 'page-2', 'restorationIndex' => 2]], $facts['entryState']);
        // No Turbo visit, no reload
        self::assertSame(0, $facts['turboVisits']);
        self::assertNotNull($facts['lastLayerBackAt']);
    }

    public function testCloseIsExactlyOneBack(): void
    {
        self::assertSame(['backs' => 1, 'closes' => ['modal:back'], 'index' => 0, 'length' => 2], $this->case('close is one back'));
    }

    public function testNoBackWhenTheCurrentEntryIsNotTheLayers(): void
    {
        self::assertSame(['backs' => 0, 'closes' => ['menu:close'], 'isOpen' => false, 'turboStarted' => true], $this->case('state mismatch'));
    }

    public function testAnOpenWaitsForThePopOfAPendingClose(): void
    {
        $facts = $this->case('open during pending close');

        // Only the first layer's push before the pop, the second after it: never back() + pushState() racing
        self::assertSame(1, $facts['pushesBeforePop']);
        self::assertSame(2, $facts['pushesAfter']);
        self::assertSame(['menu:back'], $facts['closes']);
        self::assertSame('modal', $facts['current']);
        self::assertSame(1, $facts['index']);
        self::assertSame(['mspLayer' => 'modal'], $facts['state']);

        $capped = $this->case('open waits at most 300 ms');
        self::assertSame('menu', $capped['at299']);
        self::assertSame('modal', $capped['at301']);
        self::assertSame(['menu:back'], $capped['closes']);
        self::assertSame(1, $capped['warned']);
    }

    public function testEveryPageVisitWhileALayerHoldsTheEntryIsMarkedReplaceAndNoFrameNavigationIs(): void
    {
        self::assertSame([
            'page link' => 'replace',
            '_top link inside a frame' => 'replace',
            'link in a frame with target _top' => 'replace',
            '_top link inside the modal frame' => 'replace',
            'tab under the Menu' => 'replace',
            'Menu row' => 'replace',
            // Marking a frame link would promote it to a page visit
            'modal-frame link' => 'advance',
            'link inside the modal frame' => 'advance',
            'link inside a frame' => 'advance',
            'page link, no layer' => 'advance',
        ], $this->case('replace marking'));

        self::assertSame([
            'page-link' => true,
            'top-in-frame' => true,
            'in-top-frame' => true,
            'in-frame' => false,
            'opens-modal' => false,
            'modal-frame-link' => false,
            'modal-top-link' => true,
            'modal-top-form' => true,
            'modal-frame-form' => false,
        ], $this->case('startsPageVisit'));
    }

    public function testTheMarkerIsPutBackAtTurboVisitAndTheLayerReleased(): void
    {
        self::assertSame(['action' => 'replace', 'afterVisit' => 'advance', 'closes' => ['menu:leave'], 'turboStarted' => true], $this->case('marker restored'));
    }

    public function testAFormMarkerOutlivesSubmitEndBecauseTurboReadsTheActionAfterIt(): void
    {
        $facts = $this->case('form marking');

        // Turbo 8.0.23: turbo:submit-end fires before formSubmissionSucceededWithResponse reads the action (F4)
        self::assertSame(['atSubmitStart' => 'replace', 'atSubmitEnd' => 'replace', 'action' => 'replace'], $facts['pageForm']);
        self::assertNull($facts['restoredAtVisit']);
        // A frame form (Turbo-Frame header) and a form without a layer stay untouched
        self::assertSame(['atSubmitStart' => null, 'atSubmitEnd' => null, 'action' => 'advance'], $facts['frameForm']);
        self::assertSame(['atSubmitStart' => null, 'atSubmitEnd' => null, 'action' => 'advance'], $facts['noLayer']);
    }

    public function testLeavingFromALayerLeavesNoDuplicateEntry(): void
    {
        $link = $this->case('leave by link');
        self::assertSame(['/en/hub', '/en/puzzle', '/en/faq'], $link['urls']);
        self::assertSame(2, $link['index']);
        self::assertSame(['modal:leave'], $link['closes']);
        self::assertTrue($link['turboStarted']);
        self::assertSame(2, $link['state']);

        $form = $this->case('leave by form');
        self::assertSame('replace', $form['action']);
        self::assertSame(['/en/hub', '/en/faq', '/en/compare'], $form['urls']);
        self::assertSame(2, $form['index']);
    }

    public function testALinkToTheLayersOwnPageGoesBackFirst(): void
    {
        self::assertSame([
            'prevented' => true,
            'markedAction' => 'advance',
            'visitsBeforePop' => 0,
            'visit' => ['url' => '/en/faq', 'action' => 'replace'],
            'backStack' => ['/en/hub', '/en/faq'],
            'closes' => ['modal:back'],
        ], $this->case('leave to the same page'));
    }

    public function testNonTurboLinksReplaceTheLayersEntry(): void
    {
        $facts = $this->case('non-Turbo links');

        foreach (['data-turbo=false' => 'https://msp.test/logout', 'external' => 'https://example.org/x'] as $name => $url) {
            self::assertSame(['prevented' => true, 'location' => [['replace', $url]], 'closes' => ['menu:leave'], 'isOpen' => false], $facts[$name], $name);
        }

        // A download does not navigate; a modified click opens elsewhere: both left to the browser
        foreach (['download', 'ctrl click external'] as $name) {
            self::assertSame(['prevented' => false, 'location' => [], 'closes' => [], 'isOpen' => true], $facts[$name], $name);
        }

        self::assertSame(['prevented' => false, 'location' => []], $facts['no layer']);
    }

    public function testResumeKeepsTheRestorationIdentifierAndIndex(): void
    {
        $facts = $this->case('resume keeps identity');

        self::assertSame(['id' => 'page-3', 'index' => 3], $facts['before']);
        self::assertSame(['id' => 'page-3', 'index' => 3, 'state' => ['restorationIdentifier' => 'page-3', 'restorationIndex' => 3]], $facts['afterBack']);
        self::assertSame(['id' => 'page-3', 'index' => 3], $facts['afterClose']);
        self::assertSame(2, $facts['starts']);
    }

    public function testAPopToAnotherUrlClosesTheLayerAndStartsOneRestoreVisit(): void
    {
        self::assertSame([
            'closes' => ['menu:back'],
            'url' => '/en/puzzle',
            'restores' => [['url' => '/en/puzzle', 'restorationIdentifier' => 'page-1']],
            // P0-03: one history line for the traversal
            'historyBeacons' => ['turbo_restore'],
        ], $this->case('pop to another URL'));
    }

    public function testALayerCloseIsNeverCountedAsABackNavigation(): void
    {
        self::assertSame(['afterLayerClose' => 0, 'afterRealBack' => ['turbo_restore'], 'turboRestores' => 1], $this->case('back logging'));
    }

    public function testForwardOntoAClosedLayersEntryDoesNotReopenIt(): void
    {
        self::assertSame(['closes' => ['menu:back'], 'isOpen' => false, 'visits' => 0, 'emptyPops' => 1, 'url' => '/en/faq'], $this->case('dead entry ignored'));
    }

    public function testInsideTheNativeAppNothingIsPushedAndCloseNeedsNoBack(): void
    {
        self::assertSame([
            'opened' => ['calls' => [], 'stops' => 0, 'isOpen' => true],
            'calls' => [],
            'closes' => ['menu:close', 'card:swap'],
            'current' => 'modal',
            'linkAction' => 'advance',
        ], $this->case('native app'));
    }

    public function testEscapeClosesTheTopLayerUnlessTakenOrAForeignModalDialogIsOpen(): void
    {
        self::assertSame([
            'plain' => ['handled' => true, 'closes' => ['menu:back']],
            'prevented' => [],
            'foreignDialog' => [],
            'ownModalDialog' => ['desktop-menu:back'],
            'otherKey' => [],
        ], $this->case('escape'));
    }

    public function testTheSafetyNetTurnsAnUnmarkedVisitIntoAReplace(): void
    {
        self::assertSame(['action' => 'replace', 'urls' => ['/en/hub', '/en/faq', '/en/puzzle'], 'warned' => 1], $this->case('safety net'));
    }

    public function testPageStatesAreTurboShaped(): void
    {
        $facts = $this->case('page state shape');

        // The entry a page owner adopts keeps Turbo's identifier and index
        self::assertSame(['turbo' => ['restorationIdentifier' => 'page-1', 'restorationIndex' => 1], 'mspPage' => 'results'], $facts['replaced']);
        self::assertSame(['owner' => 'results', 'index' => 2, 'idIsUuid' => true], $facts['pushed']);
        self::assertSame('https://msp.test/en/puzzlers/abc/results?sort=fastest&page=2', $facts['url']);
        self::assertSame(3, $facts['length']);
    }

    public function testBackPastAPageOwnersEntriesReleasesItAndRestoresThePreviousPage(): void
    {
        $facts = $this->case('page owner pop');

        // Back within the owner's entries: the page's own handler, no Turbo
        self::assertSame(['ownPops' => ['results'], 'visits' => 0, 'turboStarted' => false], $facts['ownBack']);
        // One more Back: the Hub, through one restore visit with its own identifier (F3, decision 93)
        self::assertSame(['results'], $facts['ownPops']);
        self::assertSame('/en/hub', $facts['url']);
        self::assertSame([['url' => '/en/hub', 'restorationIdentifier' => 'page-0']], $facts['restores']);
        self::assertTrue($facts['turboStarted']);
        self::assertSame(0, $facts['currentIndex']);
    }

    public function testALayerOverAPageOwner(): void
    {
        $facts = $this->case('layer over page owner');

        self::assertSame(['ownPops' => [], 'closes' => ['menu:back'], 'turboStarted' => false, 'visits' => 0], $facts['closeOnly']);
        self::assertSame([
            'ownPops' => ['results'],
            'closes' => ['menu:back', 'menu:back'],
            'turboStarted' => false,
            'visits' => 0,
            'url' => 'https://msp.test/en/puzzlers/abc/results',
        ], $facts['longPress']);

        self::assertSame(['sameOwnPage' => false, 'otherPage' => true], $this->case('page owner leaves'));
    }

    public function testMissingTurboInternalsFallBackWithOneWarningEach(): void
    {
        $facts = $this->case('runtime fallbacks');

        // No currentIndex / replace: plain start() (today's behaviour), warned once for two closes
        self::assertSame(['starts' => 2, 'started' => true, 'closes' => ['menu:back', 'menu:back'], 'warnings' => 1], $facts['noIdentity']);
        // No navigator.currentVisit: no safety net, warned once for two unmarked visits
        self::assertSame(['closes' => ['modal:leave'], 'warnings' => 1], $facts['noNavigator']);
        // No Turbo at all: entries still work, a visit from the layer replaces its entry
        self::assertSame(['closes' => ['menu:back'], 'location' => [['replace', '/en/puzzle']]], $facts['noTurbo']);
    }

    public function testVisitFromLayerCloseThenCloseForCacheAndBindDialog(): void
    {
        self::assertSame([
            ['url' => '/en/puzzle', 'action' => 'advance', 'response' => true],
            ['url' => '/en/help', 'action' => 'replace', 'response' => true],
        ], $this->case('visitFromLayer'));

        self::assertSame(['order' => ['no layer', 'requested', 'closed', 'then'], 'backs' => 1], $this->case('closeThen'));

        self::assertSame([
            'closes' => ['menu:cache'],
            'backs' => 0,
            'turboStarted' => true,
            'leftCloses' => ['modal:leave', 'modal:cache'],
        ], $this->case('closeForCache'));

        self::assertSame([
            'cancelPrevented' => true,
            'backsAfterCancel' => 1,
            'backs' => 2,
            'closes' => ['overlay:back', 'overlay:back'],
        ], $this->case('bindDialog'));

        self::assertSame(['layer' => 'mspLayer', 'page' => 'mspPage'], $this->case('constants'));
    }

    /**
     * @return array<mixed>
     */
    private function case(string $name): array
    {
        $facts = $this->results()[$name] ?? null;
        self::assertIsArray($facts, $name . ' has no result in tests/layer-history-harness.mjs');
        self::assertArrayNotHasKey('threw', $facts, $name);

        return $facts;
    }

    /**
     * @return array<string, mixed>
     */
    private function results(): array
    {
        if (self::$results === null) {
            $node = new ExecutableFinder()->find('node');
            self::assertIsString($node, 'node is required to execute the module - it is part of the base image');

            $process = new Process([$node, __DIR__ . '/layer-history-harness.mjs']);
            $process->mustRun();

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::$results = $decoded;
        }

        return self::$results;
    }
}
