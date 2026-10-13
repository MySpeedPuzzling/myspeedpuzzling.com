<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The Turbo internals the navigation helpers rely on, pinned against the real dist (docs/features/navigation
 * implementation plan decision 92, review F5). assets/layer_history.js uses session.history.{stop, start, replace,
 * currentIndex, restorationIdentifier} and session.navigator.currentVisit.action, marks a link in turbo:click and a
 * form in turbo:submit-start; GEST-12/17 rely on the view-transition decision reading the live head meta.
 *
 * @hotwired/turbo is pinned to exactly 8.0.23 because of them. A Turbo upgrade must keep this test green and re-run the
 * browser checks of GEST-01, GEST-12, GEST-15 and GEST-17; the helpers also detect missing internals at runtime and fall
 * back to today's behaviour with one console.warn (tests/LayerHistoryScriptTest.php "runtime fallbacks").
 *
 * Runs tests/turbo-contract-harness.mjs: the real dist in jsdom, fetch answering HTML.
 */
final class TurboContractScriptTest extends TestCase
{
    private const string VERSION = '8.0.23';

    /** @var null|array<string, mixed> */
    private static null|array $facts = null;

    public function testTurboIsPinnedExactly(): void
    {
        $package = $this->json('package.json');
        self::assertIsArray($package['dependencies'] ?? null);
        self::assertSame(self::VERSION, $package['dependencies']['@hotwired/turbo'] ?? null, 'package.json pins @hotwired/turbo exactly (no ^ or ~)');

        $lock = $this->json('package-lock.json');
        self::assertIsArray($lock['packages'] ?? null);
        self::assertIsArray($lock['packages']['node_modules/@hotwired/turbo'] ?? null);
        self::assertSame(self::VERSION, $lock['packages']['node_modules/@hotwired/turbo']['version'] ?? null);

        self::assertSame(self::VERSION, $this->facts()['version'], 'node_modules holds another Turbo than the lock');
    }

    public function testTheHistoryInternalsExist(): void
    {
        self::assertSame([
            'stop' => 'function',
            'start' => 'function',
            'replace' => 'function',
            'currentIndex' => 'number',
            'restorationIdentifier' => 'string',
            'started' => true,
        ], $this->facts()['history']);

        self::assertSame([
            'navigator' => 'object',
            'visit' => 'function',
            'historyPoppedWithEmptyState' => 'function',
            'historyPoppedToLocationWithRestorationIdentifierAndDirection' => 'function',
        ], $this->facts()['internals']);
    }

    public function testStartMintsANewRestorationIdentifierAndReplaceWritesTheOneGiven(): void
    {
        // Why the helper restores the identity after start(): Turbo's saved scroll position is keyed by it
        self::assertSame(['stoppedFlag' => false, 'mintsNewIdentifier' => true, 'stateCarriesIt' => true, 'indexFromState' => 7], $this->facts()['start']);
        self::assertSame([
            'state' => ['turbo' => ['restorationIdentifier' => 'kept-identifier', 'restorationIndex' => 3]],
            'restorationIdentifier' => 'kept-identifier',
        ], $this->facts()['replace']);
    }

    public function testALinksActionIsReadAfterTurboClickAndHistoryChangesOnlyAtRender(): void
    {
        self::assertSame([
            'atClick' => ['target' => 'link', 'cancelable' => true, 'hasOriginalEvent' => true],
            // data-turbo-action set inside turbo:click is the visit's action; at turbo:visit nothing changed yet
            'atVisit' => ['action' => 'replace', 'currentVisitAction' => 'replace', 'urlUnchanged' => true, 'lengthUnchanged' => true],
            'path' => '/en/linked',
            'lengthAfter' => 0,
        ], $this->facts()['link']);
    }

    public function testTheVisitsActionCanStillBeChangedAtTurboVisit(): void
    {
        // The safety net: navigator.currentVisit.action set to replace at turbo:visit makes changeHistory replace
        self::assertSame([
            'replaced' => [
                'atVisit' => ['action' => 'advance', 'hasCurrentVisit' => true, 'writable' => true],
                'path' => '/en/programmatic',
                'lengthAfter' => 0,
            ],
            'plain' => ['path' => '/en/plain', 'lengthAfter' => 1],
        ], $this->facts()['safetyNet']);
    }

    public function testAFormsActionIsReadAfterSubmitEnd(): void
    {
        // Review F4: the marker set at submit-start must survive submit-end - Turbo reads it after the response body
        self::assertSame([
            'order' => ['submit-start', 'submit-end', 'visit'],
            'headerAtStart' => null,
            'visitAction' => 'replace',
            'path' => '/en/after',
            'lengthAfter' => 0,
        ], $this->facts()['pageForm']);

        // A frame form carries the Turbo-Frame header already at submit-start (the helper never marks it)
        self::assertSame([
            'headerAtStart' => 'frame',
            'request' => ['method' => 'POST', 'path' => '/en/frame-submit', 'turboFrame' => 'frame'],
        ], $this->facts()['frameForm']);
    }

    public function testARestoreVisitTakesTheRestorationIdentifierGiven(): void
    {
        self::assertSame(['action' => 'restore', 'restorationIdentifier' => 'restore-me'], $this->facts()['restoreVisit']);
        // layer_history restoreVisit (review F3): Navigator#startVisit restores without proposing (no turbo:before-visit)
        self::assertSame(['type' => 'function', 'proposals' => 0, 'action' => 'restore', 'restorationIdentifier' => 'restore-direct'], $this->facts()['startVisit']);
    }

    public function testTheViewTransitionDecisionReadsTheLiveHeadMeta(): void
    {
        self::assertSame([
            'without' => false,
            'sameOrigin' => true,
            'none' => false,
            'againSameOrigin' => true,
            'shouldTransitionTo' => 'function',
        ], $this->facts()['viewTransition']);
    }

    /**
     * NAV-07 (docs/features/navigation README §4.3 "State"): a visit replaces <body> and copies only lang and dir onto
     * <html> - the client's flags (the head scripts' data-os / data-nav-layout / data-nav-collapsed / is-standalone, the
     * top bar's data-topbar, a controller's inline custom property) stay, and nothing else of the new page's <html>
     * arrives (server state never goes on <html> except data-native-app, which never changes within a session). Turbo
     * scrolls the new page after turbo:render; assets/navigation/visit_scroll.js makes that scroll instant (app.scss's
     * html { scroll-behavior: smooth } would glide from the previous page's offset) and lets go by turbo:load.
     */
    public function testHtmlKeepsTheClientsAttributesAndTheVisitsScrollIsInstant(): void
    {
        self::assertSame([
            'path' => '/de/attributes',
            'lang' => 'de',
            'dir' => 'ltr',
            'server' => ['dataServer' => null, 'classFromServer' => false],
            'client' => [
                'topbar' => 'hidden',
                'layout' => 'rail',
                'collapsed' => 'help about',
                'os' => 'ios',
                'standalone' => true,
                'compactBar' => '59px',
            ],
            'order' => [
                'turbo:before-render:auto',
                'turbo:render:auto',
                'scrollTo(0, 0):auto',
                'turbo:load:',
            ],
            'behaviorAfter' => '',
        ], $this->facts()['htmlAcrossVisit']);
    }

    /**
     * @return array<string, mixed>
     */
    private function facts(): array
    {
        if (self::$facts === null) {
            $node = new ExecutableFinder()->find('node');
            self::assertIsString($node, 'node is required to load the dist - it is part of the base image');

            $process = new Process([$node, __DIR__ . '/turbo-contract-harness.mjs']);
            $process->setTimeout(120);
            $process->mustRun();

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::$facts = $decoded;
        }

        return self::$facts;
    }

    /**
     * @return array<string, mixed>
     */
    private function json(string $file): array
    {
        $contents = file_get_contents(dirname(__DIR__) . '/' . $file);
        self::assertIsString($contents, $file);

        /** @var array<string, mixed> $decoded */
        $decoded = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);

        return $decoded;
    }
}
