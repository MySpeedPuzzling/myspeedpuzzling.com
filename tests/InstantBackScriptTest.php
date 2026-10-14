<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Instant Back (docs/features/navigation README §4.13 G9; requirements GEST-14 and GEST-15; decisions 64, 65, 66, 99):
 * assets/instant_back.js run under node (tests/instant-back-harness.mjs, jsdom for the DOM parts).
 *
 * - GEST-14: a back/forward-cache restore is kept unless the page was away > 10 minutes (or for an unknown time), carries
 *   [data-bfcache-reload], the viewer changed, a POST left from it, it is an iPhone while INSTANT_BACK_IOS is off, or the
 *   app; KEEP_BFCACHE = false reloads every one (the revert). The pagehide stamp is per document in sessionStorage (the
 *   acceptance script ages it there), in memory when storage is blocked.
 * - GEST-15: a restore visit renders Turbo's snapshot only when INSTANT_BACK is on, the snapshot of that URL is at most
 *   10 minutes old, for the same viewer, still in Turbo's cache, and not an iPhone with INSTANT_BACK_IOS off - otherwise
 *   `location.href = url` (Turbo's own visit cancelled, the restore mark for PROF-05c set); inside the app 'leave'.
 *   scroll-behavior: auto holds from that visit to one frame after turbo:load.
 * - The clean copy: nothing open (modals and backdrops, dropdowns, dialogs incl. the Menu shell and the card, the Menu
 *   back in its slot, <details> menus - not the Results filters), no announcement modal (LAUNCH review Q2), no ripple,
 *   inline view-transition name, celebration or toast, no inert page; widgets torn down. The live page is changed only
 *   after a view transition captured it (the cache work waits a task when one will), never by a cache that leaves the
 *   page in place (no visit; a frame visit that renders nothing).
 * - Staleness (decision 65): a successful non-GET submission, a post_action success and a [data-clears-snapshots] click
 *   clear Turbo's cache and the ledger; a GET or a refused POST does not.
 */
final class InstantBackScriptTest extends TestCase
{
    /** @var null|array<string, mixed> */
    private static null|array $results = null;

    public function testNoCaseThrew(): void
    {
        foreach (self::results() as $case => $result) {
            self::assertFalse(is_array($result) && array_key_exists('threw', $result), $case . ': ' . json_encode($result));
        }
    }

    public function testTheSwitchesAndTheContract(): void
    {
        self::assertSame([
            // The revert of GEST-15 (docs/features/feature_flags.md)
            'INSTANT_BACK' => true,
            // Decision 66: true because GEST-13 is verified
            'INSTANT_BACK_IOS' => true,
            // The revert of GEST-14
            'KEEP_BFCACHE' => true,
            'MAX_AGE_MS' => 600000,
            'SNAPSHOT_RESTORES' => true,
            'SNAPSHOT_LIMIT' => 10,
            'PAGEHIDE_KEY_PREFIX' => 'msp:pagehide:',
            'VIEWER_KEY' => 'msp:viewer',
            'VIEWER_META' => 'msp-viewer',
            'RESTORE_RELOAD_KEY' => 'msp:restore-reload',
            'BFCACHE_RELOAD_ATTRIBUTE' => 'data-bfcache-reload',
            'CLEARS_SNAPSHOTS_ATTRIBUTE' => 'data-clears-snapshots',
            'BEFORE_CACHE_EVENT' => 'msp:before-cache',
            'RESTORE_DECIDED_EVENT' => 'msp:restore-decided',
            'NO_CACHE' => 'no-cache',
            'NO_PREVIEW' => 'no-preview',
            'BODY_LAYER_CLASSES' => ['modal-open', 'global-search-shown'],
        ], self::results()['constants']);
    }

    public function testTheBackForwardCacheVerdict(): void
    {
        self::assertSame([
            'notPersisted' => 'none',
            'fresh' => 'keep',
            'desktop' => 'keep',
            'tenMinutes' => 'keep',
            'elevenMinutes' => 'reload',
            'unknownAway' => 'reload',
            'negativeAway' => 'reload',
            'reloadMarker' => 'reload',
            'viewerChanged' => 'reload',
            'postedFrom' => 'reload',
            'wroteSince' => 'reload',
            'iosWithoutTheSwitch' => 'reload',
            'iosWithTheSwitch' => 'keep',
            // Today's unconditional reload inside the app
            'nativeApp' => 'reload',
            'reverted' => 'reload',
        ], self::results()['bfcache verdict']);
    }

    public function testTheRestoreDecision(): void
    {
        self::assertSame([
            'fresh' => 'snapshot',
            'desktop' => 'snapshot',
            'exactlyTenMinutes' => 'snapshot',
            'elevenMinutes' => 'reload',
            'cachedInTheFuture' => 'reload',
            'noSnapshot' => 'reload',
            'turboForgotIt' => 'reload',
            'otherViewer' => 'reload',
            'writtenAfterTheCopy' => 'reload',
            'writtenBeforeTheCopy' => 'snapshot',
            'guestBoth' => 'snapshot',
            'iosWithoutTheSwitch' => 'reload',
            'iosWithTheSwitch' => 'snapshot',
            'reverted' => 'reload',
            'nativeApp' => 'leave',
            'nativeAppReverted' => 'leave',
        ], self::results()['restore decision']);
    }

    public function testTheCacheWorkWaitsForAViewTransitionOnly(): void
    {
        self::assertSame([
            'androidPlaceSwitch' => 'after-capture',
            'iosOrDesktop' => 'now',
            'noApi' => 'now',
            'reducedMotion' => 'now',
            'nativeApp' => 'now',
        ], self::results()['cache work timing']);

        self::assertSame([
            // In the frame of turbo:before-cache nothing of the live page changes
            'beforeTask' => ['closeForCache' => 0, 'events' => 0, 'timers' => 1],
            // One task later (before Turbo's clone, queued after it) the layers close and the owners tear down
            'afterTask' => ['closeForCache' => 1, 'events' => 1],
            'iosAtOnce' => ['closeForCache' => 1, 'timers' => 0],
        ], self::results()['cache work timing in the page']);
    }

    public function testTheLedgerKeepsWhatTurboKeeps(): void
    {
        self::assertSame([
            'afterTwelve' => ['size' => 10, 'first' => null, 'third' => ['cachedAt' => 3, 'viewer' => '']],
            'reCached' => ['third' => ['cachedAt' => 30, 'viewer' => 'v'], 'fourth' => null],
            'forgotten' => null,
            'cleared' => 0,
            'keyWithoutHash' => 'https://msp.test/en/hub',
            'keyJunk' => null,
        ], self::results()['ledger']);
    }

    public function testTheCopyTurboCachesIsClean(): void
    {
        $clean = [
            'announcementGone' => true,
            'dynamicModal' => ['cls' => 'modal fade', 'display' => 'none', 'ariaHidden' => 'true', 'ariaModal' => null],
            'backdrops' => 0,
            'bodyClass' => 'keep-me',
            'bodyStyle' => '',
            'bodyBsAttributes' => [],
            'fixedTop' => 'padding-right: 4px;',
            'inert' => [],
            'burgerExpanded' => 'false',
            'dropdown' => ['menu' => 'dropdown-menu', 'popper' => null, 'style' => 'color: red;', 'toggle' => '', 'toggleExpanded' => 'false'],
            // The Results filters keep their state; menus close
            'filtersOpen' => true,
            'languageOpen' => false,
            'ripples' => 0,
            'thumbStyle' => 'width: 10px;',
            'recap' => '',
            'confettiHosts' => 0,
            'toasts' => 0,
            'openDialogs' => 0,
            'cardDialogs' => 0,
            'menuShell' => 'menu-layer',
            'siteMenuInSlot' => 'menu-slot',
        ];

        self::assertSame([...$clean, 'menuOrdered' => ['site-menu']], self::results()['sanitize live body']);
        self::assertSame([...$clean, 'liveUntouched' => 1], self::results()['sanitize detached copy']);
        self::assertSame('ok', self::results()['sanitize never throws']);
    }

    public function testWidgetsAreTornDown(): void
    {
        self::assertSame([
            'count' => 3,
            'destroyed' => ['tomselect', 'flatpickr', 'chart'],
            'nothingToTearDown' => 0,
        ], self::results()['widgets']);
    }

    public function testASnapshotRestoreInThePage(): void
    {
        self::assertSame([
            'recorded' => ['cachedAt' => 1700000000000, 'viewer' => 'v1'],
            'cacheWork' => ['closeForCache' => 1, 'beforeCacheEvents' => 1],
            'atVisit' => ['isSnapshotRestore' => true, 'kind' => 'snapshot', 'age' => 240000, 'scrollBehavior' => 'auto', 'assigned' => 0, 'cancels' => 0],
            'copyClean' => ['backdrops' => 0, 'modal' => 'modal', 'prevented' => false],
            'scrollHeldThroughLoad' => 'auto',
            'scrollAfterFrame' => '',
            'decided' => ['snapshot'],
            'afterVisit' => ['isSnapshotRestore' => false, 'kind' => null, 'age' => null],
        ], self::results()['snapshot restore']);
    }

    public function testEveryOtherRestoreIsAFullLoad(): void
    {
        self::assertSame([
            'elevenMinutes' => [
                'assigned' => ['https://msp.test/en/hub'],
                'cancels' => 1,
                'mark' => 'https://msp.test/en/hub',
                'renderPrevented' => true,
                'kind' => 'reload',
                'cacheWorkAfter' => 0,
            ],
            'notCached' => ['assigned' => ['https://msp.test/en/stopwatch']],
            'evicted' => ['assigned' => ['https://msp.test/en/hub']],
            'nativeApp' => ['assigned' => [], 'kind' => null, 'decided' => 0],
        ], self::results()['reload restores']);
    }

    public function testACacheThatLeavesThePageInPlaceChangesNothing(): void
    {
        self::assertSame([
            'withoutVisit' => ['recorded' => 0, 'work' => 0],
            'frameVisit' => ['recorded' => 0, 'work' => 0],
        ], self::results()['caches that leave the page as it is']);
    }

    /** A Live component's URL-bound props rewrote the query: the copy is cached under the address shown */
    public function testAPageThatRewroteItsAddressIsCachedUnderIt(): void
    {
        self::assertSame([
            'recorded' => ['shown' => true, 'rendered' => false, 'turboKey' => 'https://msp.test/en/hub?scope=cz'],
            'restore' => ['turboKey' => 'https://msp.test/en/hub'],
        ], self::results()['a page that rewrote its own address']);
    }

    public function testWritesClearTheSnapshots(): void
    {
        self::assertSame([
            'recorded' => 1,
            'afterGet' => ['recorded' => 1, 'clears' => 0],
            'afterRefusedPost' => ['recorded' => 1, 'clears' => 0],
            'afterPost' => ['recorded' => 0, 'clears' => 1],
            'afterFavorite' => ['recorded' => 0, 'clears' => 2],
            'cleared' => 0,
        ], self::results()['staleness']);
    }

    public function testTheBackForwardCacheInThePage(): void
    {
        self::assertSame([
            'fiveMinutes' => 'keep',
            'kindAfterKeep' => 'bfcache',
            'stamped' => true,
            'notPersisted' => 'none',
            'agedStamp' => 'reload',
            'blockedStorageInMemory' => 'keep',
            'blockedStorageOld' => 'reload',
            'reloadMarker' => 'reload',
            'viewerChanged' => 'reload',
            'guestRemembered' => '',
            'guestStillGuest' => 'keep',
            'guestSignedInMeanwhile' => 'reload',
            'unknownViewer' => 'keep',
            'viewerRemembered' => 'v1',
            'postedFrom' => 'reload',
            'postActionPosted' => 'reload',
            'nativeApp' => 'reload',
        ], self::results()['bfcache in the page']);
    }

    public function testADocumentLoadedByARestoreKnowsIt(): void
    {
        self::assertSame([
            'cameBack' => true,
            'kind' => 'reload',
            'markLeft' => null,
            'kindAfterVisit' => null,
            'cameBackAfterVisit' => false,
        ], self::results()['a restored document']);
    }

    /**
     * Staleness (decision 65): every same-origin write made with fetch() - a Live component action, a dismissed hint, a
     * post_action - clears the snapshots and marks the document as having posted (its back/forward-cache copy reloads),
     * like a Turbo form submission; reads, a Live component's plain re-render and the telemetry under /-/ change nothing;
     * a refused write changes nothing either
     */
    public function testFetchWritesClearTheSnapshots(): void
    {
        self::assertSame([
            'rule' => [
                'get' => false,
                'head' => false,
                'post' => true,
                'put' => true,
                'liveAction' => true,
                'liveBatch' => true,
                'liveRerender' => false,
                'liveRerenderSlash' => false,
                'telemetry' => false,
                'otherOrigin' => false,
                'junk' => false,
            ],
            'answers' => [
                'readsOnly' => ['clears' => 0, 'verdict' => 'keep'],
                'liveAction' => ['answerPassedOn' => true, 'clears' => 1, 'ledgerEntry' => null, 'verdict' => 'reload'],
                'refusedWrite' => ['clears' => 0],
                'requestObject' => ['clears' => 1],
                'wrappedOnce' => true,
            ],
            'sent' => 6,
        ], self::results()['fetch writes']);
    }

    /**
     * A write anywhere in this browser - another tab, the next document, a page whose GET wrote (<meta
     * name="msp-clears-snapshots">: notifications marked read, a stopwatch started, a conversation read) - is never
     * hidden by a copy taken before it: localStorage msp:last-write against the pagehide stamp and the snapshot's age
     */
    public function testAWriteElsewhereOutdatesTheCopies(): void
    {
        self::assertSame([
            'bfcacheAfterAWriteElsewhere' => 'reload',
            'bfcacheAfterAnOlderWrite' => 'keep',
            'snapshotAfterAWriteElsewhere' => ['kind' => 'reload', 'assigned' => 1],
            'postFormSubmitted' => true,
            'pageThatWrote' => ['clears' => 1, 'ledger' => null, 'lastWrite' => true],
            'pageThatDidNot' => ['clears' => 1],
        ], self::results()['writes elsewhere']);
    }

    /**
     * A Back taken right after the next page rendered: Turbo reports that page's scroll to the top a frame later, into the
     * identifier the popstate has just made current - the entry being restored. The snapshot restore comes back to the
     * scroll the entry was left at (held at turbo:visit, put back at turbo:render - before Turbo scrolls), once; a reload
     * and an ordinary visit are left alone (PROF-05c "layer: a Menu row", the coordinator's 2 of 3)
     */
    public function testALateScrollEventNeverMovesARestore(): void
    {
        self::assertSame([
            'snapshot' => ['kind' => 'snapshot', 'position' => ['x' => 0, 'y' => 1200], 'other' => 'kept'],
            'afterwards' => ['x' => 0, 'y' => 300],
            'noPosition' => null,
            'reload' => ['kind' => 'reload', 'position' => ['x' => 0, 'y' => 0]],
            'advance' => ['x' => 0, 'y' => 5],
        ], self::results()['a late scroll event never moves a restore']);
    }

    /**
     * GEST-15 (e): document.fonts.ready is read while the fonts may still change a measure - once it resolved in the
     * document, a controller connecting on a later render (a Back from Turbo's snapshot) does not read it: in Blink the
     * read lays the whole page out in the middle of the render (assets/fonts_settled.js)
     */
    public function testTheFontsAreAskedForWhileTheyLoadOnly(): void
    {
        self::assertSame([
            'first' => true,
            'second' => true,
            'later' => false,
            'calls' => ['first', 'second'],
            'readsBeforeSettled' => 2,
            'readsAfterSettled' => 0,
            'noFonts' => false,
        ], self::results()['fonts asked while they load only']);
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

        $process = new Process([$node, __DIR__ . '/instant-back-harness.mjs']);
        $process->mustRun();

        /** @var array<string, mixed> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return self::$results = $results;
    }
}
