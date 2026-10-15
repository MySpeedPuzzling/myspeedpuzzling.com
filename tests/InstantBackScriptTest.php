<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;
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
            'CACHE_CONTROL_META' => 'turbo-cache-control',
            // F11: ~16 ms of Turbo's clone at 4x CPU throttling - an element ~2.7 µs, a picture ~125 µs (measured)
            'CLONE_BUDGET_UNITS' => 6000,
            'PICTURE_UNITS' => 48,
            'SCRIPT_BUILT_LAYERS' => '.ev-menu-layer, .ev-follow-note',
            'REBUILT_ON_CONNECT' => '.pwa-ptr-indicator, #imageEditorModal',
            'MODAL_FRAME_ID' => 'modal-frame',
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
            // F3: the viewer of the last page this browser loaded (any tab) is not the copy's - signed out, in or as
            // somebody else elsewhere
            'signedOutElsewhere' => 'reload',
            'otherAccountElsewhere' => 'reload',
            'sameViewerStored' => 'snapshot',
            'storedViewerUnknown' => 'snapshot',
            'guestCopySignedInElsewhere' => 'reload',
        ], self::results()['restore decision']);
    }

    /** F1 / F11: the page a visit leaves keeps no copy when a write starts the visit or the page is too large to copy */
    public function testWhichPagesKeepNoCopy(): void
    {
        self::assertSame([
            'plainVisit' => false,
            'byWrite' => true,
            'frameVisitByWrite' => false,
            'largeByElements' => true,
            'atTheBudget' => false,
            'largeByPictures' => true,
            'largeFrameVisit' => false,
            'units' => [3543, 8821, 0, 0],
        ], self::results()['pages kept out of the cache']);
    }

    /**
     * Instant Back review F1: a page left through a write - a [data-clears-snapshots] link (Favorite, redirected back to
     * the same address: review S1), a visit a script starts while a write is unrendered (a Live action's redirect), a
     * click that wrote on its way (a beacon), a write landing while the visit was on its way - never comes back from a
     * copy taken before or around the write: its live turbo-cache-control says no-cache at turbo:visit, and the write is
     * noted again after the visit's page rendered (even a copy Turbo took anyway is outdated). A write in place followed
     * by the user's own link or GET form keeps the copy taken after it. Sign-out is a write every tab learns of.
     */
    public function testAPageLeftThroughAWriteNeverComesBackFromItsCopy(): void
    {
        self::assertSame([
            'favoriteThenBack' => [
                'cacheControlAtVisit' => 'no-cache',
                'recordedAroundTheWrite' => 1,
                'ledgerAfterTheVisit' => null,
                'writeStampedAfterTheCopy' => true,
                'back' => 'reload',
                'assigned' => 1,
            ],
            'scriptVisitAfterAWrite' => ['cacheControlAtVisit' => 'no-cache', 'back' => 'reload'],
            'userLinkAfterAWriteInPlace' => ['cacheControlAtVisit' => 'no-preview', 'back' => 'snapshot'],
            'writeDuringTheVisit' => ['recorded' => 0, 'back' => 'reload'],
            'clickThatWrote' => ['cacheControlAtVisit' => 'no-cache'],
            'frameClickThenAWrite' => ['cacheControlAtVisit' => 'no-cache'],
            'getFormAfterAWrite' => ['cacheControlAtVisit' => 'no-preview'],
            'signOutLink' => ['lastWrite' => true, 'ledger' => 0, 'bfcache' => 'reload'],
        ], self::results()['a page left through a write']);
    }

    /**
     * Instant Back review F11: a page whose clone would cost more than a frame (elements + PICTURE_UNITS per picture over
     * CLONE_BUDGET_UNITS) leaves through the full-load path - by a visit or by Back; within the budget it is copied; a
     * frame visit never changes the page
     */
    public function testAPageTooLargeToCopyLeavesThroughTheFullLoadPath(): void
    {
        self::assertSame([
            'overByElements' => 'no-cache',
            'overByPictures' => 'no-cache',
            'withinTheBudget' => ['cacheControl' => 'no-preview', 'recorded' => 1],
            'leftByBack' => 'no-cache',
            'frameVisit' => 'no-preview',
        ], self::results()['a page too large to copy']);
    }

    /**
     * Instant Back review F3: a sign-out in another tab - Back here never shows the signed-in copy (the stored viewer is
     * compared); another tab's write or viewer change drops this tab's copies at once (the storage event)
     */
    public function testAViewerChangeElsewhereOutdatesTheCopies(): void
    {
        self::assertSame([
            'signedOutInAnotherTab' => ['back' => 'reload', 'assigned' => 1],
            'sameViewer' => ['back' => 'snapshot'],
            'storageEvents' => [
                'afterOtherKey' => ['ledger' => 1, 'clears' => 0],
                'afterWrite' => ['ledger' => 0, 'clears' => 1],
                'afterViewer' => ['ledger' => 0, 'clears' => 2],
            ],
        ], self::results()['the viewer changed elsewhere']);
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
            // F2 / F7: the events ⋯ menu layer (on <body>) and a guest's "Sign in to follow" note
            'scriptLayers' => 0,
        ];

        self::assertSame([
            ...$clean,
            // The live page keeps what its controllers own (a failed visit leaves it as it was)
            'rebuiltOnConnect' => 2,
            'modalFrame' => ['children' => 1, 'src' => '/en/edit-time/1', 'complete' => true, 'dataTarget' => 'frame'],
            'menuOrdered' => ['site-menu'],
        ], self::results()['sanitize live body']);
        self::assertSame([
            ...$clean,
            // A copy about to be shown: what its controllers build again goes, the modal frame as the server renders it
            'rebuiltOnConnect' => 0,
            'modalFrame' => ['children' => 0, 'src' => null, 'complete' => false, 'dataTarget' => 'frame'],
            'liveUntouched' => 1,
        ], self::results()['sanitize detached copy']);
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
            // The visit after the POST is the write's: noted again once its page rendered (F1), then the click itself
            'afterFavorite' => ['recorded' => 0, 'clears' => 3],
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
     * Every module that puts DOM on <body> (outside its controller's element - Turbo's copy holds it, a restored copy's
     * controllers never knew it) and how that DOM stays out of a restored page (Instant Back review F2): torn down on
     * `msp:before-cache`, removed by sanitizeSnapshot(), reused by id, or never on a page that opts in
     */
    private const array BODY_APPENDERS = [
        'assets/player_card_layer.js' => ['before-cache', 'dialog.player-card-layer'],
        'assets/controllers/event_manage_menu_controller.js' => ['before-cache', '.ev-menu-layer'],
        'assets/controllers/pwa_lifecycle_controller.js' => ['rebuilt', '.pwa-ptr-indicator'],
        'assets/controllers/image_editor_controller.js' => ['rebuilt', '#imageEditorModal'],
        'assets/controllers/tab_gestures_controller.js' => ['turbo:before-cache', 'endLongPress'],
        'assets/controllers/leaderboard_window_controller.js' => ['reused by id', 'LIVE_REGION_ID'],
        'assets/last_piece_reveal/confetti.js' => ['not opted in', 'last-piece-reveal'],
        'assets/participants_sheet/views/people_view.js' => ['not opted in', 'participants-sheet'],
    ];

    public function testEveryModuleThatBuildsOnTheBodyKeepsItOutOfARestoredPage(): void
    {
        $root = dirname(__DIR__) . '/';
        $found = [];

        foreach (new Finder()->files()->in($root . 'assets')->exclude('vendor')->name('*.js') as $file) {
            if (preg_match('~document\.body\)?\.(?:append|appendChild|prepend|insertAdjacentHTML|insertAdjacentElement)\(~', $file->getContents()) === 1) {
                $found[] = 'assets/' . str_replace('\\', '/', $file->getRelativePathname());
            }
        }

        sort($found);
        $listed = array_keys(self::BODY_APPENDERS);
        sort($listed);
        self::assertSame($listed, $found, 'a module building on <body> names how its DOM stays out of a restored page');

        $instantBack = (string) file_get_contents($root . 'assets/instant_back.js');
        $constants = self::results()['constants'];
        self::assertIsArray($constants);
        $rebuiltOnConnect = $constants['REBUILT_ON_CONNECT'] ?? null;
        self::assertIsString($rebuiltOnConnect);
        $optedInScope = '';

        foreach (TurboCacheControlOptInTest::OPTED_IN_TEMPLATES as $template) {
            $optedInScope .= implode("\n", TurboCacheControlOptInTest::scope($template));
        }

        foreach (self::BODY_APPENDERS as $module => [$how, $what]) {
            $source = (string) file_get_contents($root . $module);

            match ($how) {
                // The owner listens to msp:before-cache, and sanitizeSnapshot() removes what a copy still holds
                'before-cache' => self::assertTrue(
                    preg_match('~import \\{[^}]*\\bBEFORE_CACHE_EVENT\\b[^}]*\\} from \'\\.\\.?/(?:\\.\\./)?instant_back\\.js\';~', $source) === 1
                        && preg_match('~document\\.addEventListener\\(BEFORE_CACHE_EVENT,~', $source) === 1
                        && str_contains($instantBack, $what),
                    $module,
                ),
                // Removed from a copy about to be shown; its controller builds it again
                'rebuilt' => self::assertStringContainsString($what, $rebuiltOnConnect, $module),
                'turbo:before-cache' => self::assertMatchesRegularExpression('~turbo:before-cache\',\s*this\.' . $what . '~', $source, $module),
                'reused by id' => self::assertStringContainsString('getElementById(' . $what . ')', $source, $module),
                // Its controller is on no page that opts in to Turbo's restore cache
                'not opted in' => self::assertStringNotContainsString($what, $optedInScope, $module),
            };
        }
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
