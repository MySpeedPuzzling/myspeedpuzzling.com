<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Tests\Navigation\ScssReader;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Which Turbo visits run as a view transition (docs/features/navigation README §4.13 G12 place switches, G15 the
 * thumbnail morph; requirements GEST-12, GEST-17; decisions 63, 68): assets/view_transition_gate.js run under node
 * (tests/view-transition-gate-harness.mjs) - the decision matrix os × native app × reduced motion × restore × morph ×
 * place, and install() against a fake document in Turbo 8.0.23's event order (turbo:click → turbo:visit →
 * turbo:before-fetch-response → turbo:before-render → turbo:load). The stylesheet's timings and names are pinned here
 * too; the browser part (startViewTransition calls and the animations on the ::view-transition pseudo-elements) is
 * checks/GEST-12.py and checks/GEST-17.py.
 */
final class ViewTransitionGateScriptTest extends TestCase
{
    private const string STYLES = 'assets/styles/_view-transitions.scss';

    /** @var null|array<string, mixed> */
    private static null|array $results = null;

    public function testNoCaseThrew(): void
    {
        foreach (self::results() as $case => $result) {
            self::assertFalse(is_array($result) && array_key_exists('threw', $result), $case . ': ' . json_encode($result));
        }
    }

    public function testTheContract(): void
    {
        self::assertSame([
            'META_NAME' => 'view-transition',
            'SAME_ORIGIN' => 'same-origin',
            'NONE' => 'none',
            'VT_ATTRIBUTE' => 'data-vt',
            'PLACE' => 'place',
            'MORPH' => 'morph',
            'HERO_NAME' => 'puzzle-hero',
            'HERO_SELECTOR' => 'img.puzzle-hero',
            'MORPH_SOURCE_ATTRIBUTE' => 'data-morph-source',
            'MORPH_MAX_WAIT_MS' => 400,
            'PLACE_LINK_SELECTOR' => 'a[data-nav-place], a.mr--place',
        ], self::results()['constants']);
    }

    /**
     * README §4.13: Android fades through on place links and runs every other visit as an instant view transition; both
     * phones morph; iOS and desktop otherwise switch instantly (no view transition); inside the app, on Back and with
     * reduced motion never anything
     */
    public function testTheDecisionMatrix(): void
    {
        $none = ['meta' => 'none', 'vt' => null];
        $rows = self::answer('gateFor');

        foreach (['android', 'ios', 'other'] as $device) {
            foreach (['', ' app'] as $app) {
                foreach (['', ' reduced'] as $reduced) {
                    foreach (['', ' restore'] as $restore) {
                        $key = $device . $app . $reduced . $restore;
                        $row = $rows[$key] ?? null;
                        self::assertIsArray($row, $key);

                        if ($app . $reduced . $restore !== '') {
                            self::assertSame(['idle' => $none, 'place' => $none, 'morph' => $none, 'placeAndMorph' => $none], $row, $key);

                            continue;
                        }

                        $expected = match ($device) {
                            'android' => [
                                'idle' => ['meta' => 'same-origin', 'vt' => null],
                                'place' => ['meta' => 'same-origin', 'vt' => 'place'],
                                'morph' => ['meta' => 'same-origin', 'vt' => 'morph'],
                                'placeAndMorph' => ['meta' => 'same-origin', 'vt' => 'morph'],
                            ],
                            'ios' => [
                                'idle' => $none,
                                'place' => $none,
                                'morph' => ['meta' => 'same-origin', 'vt' => 'morph'],
                                'placeAndMorph' => ['meta' => 'same-origin', 'vt' => 'morph'],
                            ],
                            default => ['idle' => $none, 'place' => $none, 'morph' => $none, 'placeAndMorph' => $none],
                        };

                        self::assertSame($expected, $row, $key);
                    }
                }
            }
        }

        self::assertSame($none, $rows['empty']);
        self::assertSame($none, $rows['junk']);
        self::assertCount(26, $rows);
    }

    public function testWhatMayMorph(): void
    {
        // The card's own puzzle page, its thumbnail decoded - never the brand link inside the card, never an image loading
        self::assertSame(['samePath' => true, 'notDecoded' => false, 'otherPath' => false, 'noSource' => false, 'nothing' => false], self::results()['morphCandidate']);
        // Decision 68: in time up to 400 ms after the tap
        self::assertSame(['at0' => true, 'at400' => true, 'at401' => false, 'at600' => false, 'noTap' => false], self::results()['morphInTime']);
        self::assertSame(['absolute' => '/en/puzzle/p1', 'relative' => '/de/puzzle/p2', 'junk' => null], self::results()['pathOf']);
    }

    public function testTheMetaAtStart(): void
    {
        self::assertSame(
            ['android' => 'same-origin', 'ios' => 'none', 'other' => 'none', 'android app' => 'none', 'android reduced' => 'none'],
            self::results()['install: the meta at start'],
        );
    }

    public function testAPlaceTapFadesOnAndroidOnly(): void
    {
        $armed = ['meta' => 'same-origin', 'vt' => 'place', 'thumbnail' => null, 'hero' => null];
        $default = ['meta' => 'same-origin', 'vt' => null, 'thumbnail' => null, 'hero' => null];
        self::assertSame(['click' => $armed, 'response' => $armed, 'render' => $armed, 'load' => $default], self::results()['install: android place tap']);

        // A content link: a view transition without animation (the meta stays, no data-vt)
        self::assertSame(['click' => $default, 'response' => $default, 'render' => $default, 'load' => $default], self::results()['install: android content link']);

        $off = ['meta' => 'none', 'vt' => null, 'thumbnail' => null, 'hero' => null];

        foreach (['install: ios place tap', 'install: desktop place tap'] as $case) {
            self::assertSame(['click' => $off, 'response' => $off, 'render' => $off, 'load' => $off], self::results()[$case], $case);
        }
    }

    public function testTheThumbnailMorphsOnBothPhones(): void
    {
        $clicked = ['meta' => 'same-origin', 'vt' => 'morph', 'thumbnail' => 'puzzle-hero', 'hero' => null];
        $rendering = ['meta' => 'same-origin', 'vt' => 'morph', 'thumbnail' => 'puzzle-hero', 'hero' => 'puzzle-hero'];

        foreach (['android' => 'same-origin', 'ios' => 'none'] as $device => $default) {
            self::assertSame(
                ['click' => $clicked, 'response' => $clicked, 'render' => $rendering, 'load' => ['meta' => $default, 'vt' => null, 'thumbnail' => null, 'hero' => null]],
                self::results()['install: ' . $device . ' morph'],
                $device,
            );
        }

        // 400 ms after the tap is still in time
        self::assertSame($rendering, self::answer('install: ios morph, answer at 400 ms')['render']);
    }

    public function testNoMorphWhereItMustNotRun(): void
    {
        $off = ['meta' => 'none', 'vt' => null, 'thumbnail' => null, 'hero' => null];
        $quiet = ['click' => $off, 'response' => $off, 'render' => $off, 'load' => $off];

        foreach (['install: desktop card', 'install: ios card, thumbnail not decoded', 'install: ios card, brand link', 'install: android card, reduced motion'] as $case) {
            self::assertSame($quiet, self::results()[$case], $case);
        }

        // Inside the app: no transition at all (NAV-14)
        self::assertSame(['card' => $quiet, 'tab' => $quiet], self::results()['install: android app card + tab']);
    }

    /** Decision 68: an answer later than 400 ms drops the morph at turbo:before-fetch-response, before Turbo decides */
    public function testASlowAnswerDropsTheMorph(): void
    {
        $ios = self::answer('install: ios morph, slow answer');
        self::assertSame(['meta' => 'same-origin', 'vt' => 'morph', 'thumbnail' => 'puzzle-hero', 'hero' => null], $ios['click']);
        self::assertSame(['meta' => 'none', 'vt' => null, 'thumbnail' => null, 'hero' => null], $ios['response']);
        self::assertSame(['meta' => 'none', 'vt' => null, 'thumbnail' => null, 'hero' => null], $ios['render']);

        // Android: back to its default - an instant view transition, nothing named
        $android = self::answer('install: android morph, slow answer');
        self::assertSame(['meta' => 'same-origin', 'vt' => null, 'thumbnail' => null, 'hero' => null], $android['render']);

        // Only the visit's own answer counts - a frame's late fetch changes nothing
        self::assertSame(['meta' => 'same-origin', 'vt' => 'morph', 'thumbnail' => 'puzzle-hero', 'hero' => null], self::results()['install: ios morph, a frame answers late']);
    }

    /** README §4.13: Back is never animated by MSP */
    public function testBackIsNeverAnimated(): void
    {
        self::assertSame([
            'restored' => ['meta' => 'none', 'vt' => null, 'thumbnail' => null, 'hero' => null],
            'load' => ['meta' => 'same-origin', 'vt' => null, 'thumbnail' => null, 'hero' => null],
        ], self::results()['install: restore after a tap']);

        self::assertSame([
            'plain' => ['meta' => 'same-origin', 'vt' => null, 'thumbnail' => null, 'hero' => null],
            'animatedByTheBrowser' => ['meta' => 'none', 'vt' => null, 'thumbnail' => null, 'hero' => null],
        ], self::results()['install: popstate']);
    }

    /** A tap whose visit never came (a leave guard), a form, a prevented click: nothing of the tap stays armed or named */
    public function testATapThatChangedItsMindLeavesNothing(): void
    {
        self::assertSame(['meta' => 'none', 'vt' => null, 'thumbnail' => null, 'hero' => null], self::results()['install: a visit elsewhere after a tap']);
        self::assertSame(['meta' => 'same-origin', 'vt' => 'place', 'thumbnail' => null, 'hero' => null], self::results()['install: the same visit with a hash']);
        self::assertSame(['meta' => 'same-origin', 'vt' => null, 'thumbnail' => null, 'hero' => null], self::results()['install: a form after a tap']);
        self::assertSame(['meta' => 'none', 'vt' => null, 'thumbnail' => null, 'hero' => null], self::results()['install: a prevented click']);
        // Never two `puzzle-hero`s (the second would abort the transition with a console warning)
        self::assertSame(['meta' => 'none', 'vt' => null, 'thumbnail' => null, 'hero' => null], self::results()['install: a second tap after a cancelled morph']);
    }

    /** The head meta is always rendered (the gate turns it off); app.js installs the gate */
    public function testTheMetaAndTheInstall(): void
    {
        $base = (string) file_get_contents(__DIR__ . '/../templates/base.html.twig');
        self::assertSame(1, substr_count($base, '<meta name="view-transition" content="same-origin">'));

        $app = (string) file_get_contents(__DIR__ . '/../assets/app.js');
        self::assertStringContainsString("import { install as installViewTransitionGate } from './view_transition_gate.js';", $app);
        self::assertStringContainsString('installViewTransitionGate({ window, document });', $app);
    }

    /**
     * README §4.13 G12 + G15 timings and decision 63's names: every transition is instant unless named; the place fade
     * (90 ms out, 210 ms in after 90 ms with the .92 scale); the morph group 300 ms, the page's fade 150 ms; the bars,
     * the rail and the column's slot named only while armed - never #site-menu, which moves into the phone shell
     */
    public function testTheStylesheet(): void
    {
        $code = ScssReader::withoutComments((string) file_get_contents(__DIR__ . '/../' . self::STYLES));
        $rules = ScssReader::rules($code);

        foreach (['::view-transition-group(*)', '::view-transition-image-pair(*)', '::view-transition-old(*)', '::view-transition-new(*)'] as $selector) {
            self::assertStringStartsWith('animation: none;', $rules[$selector] ?? '', $selector);
        }

        self::assertSame('animation: msp-vt-fade-out 90ms cubic-bezier(.3, 0, .8, .15) both;', $rules['html[data-vt="place"]::view-transition-old(root)'] ?? null);
        self::assertSame('animation: msp-vt-place-in 210ms cubic-bezier(.05, .7, .1, 1) 90ms both;', $rules['html[data-vt="place"]::view-transition-new(root)'] ?? null);
        self::assertMatchesRegularExpression('~@keyframes msp-vt-place-in \{\s*from \{\s*opacity: 0;\s*transform: scale\(\.92\);~', $code);
        self::assertSame(
            'animation: revert;animation-duration: 300ms;animation-timing-function: cubic-bezier(.05, .7, .1, 1);',
            $rules['html[data-vt="morph"]::view-transition-group(puzzle-hero)'] ?? null,
        );
        self::assertSame('height: 100%;object-fit: cover;', $rules['html[data-vt="morph"]::view-transition-old(puzzle-hero)'] ?? null);
        self::assertSame('height: 100%;object-fit: cover;', $rules['html[data-vt="morph"]::view-transition-new(puzzle-hero)'] ?? null);
        self::assertSame('animation: msp-vt-fade-in 150ms ease-out both;', $rules['html[data-vt="morph"]::view-transition-new(root)'] ?? null);

        $names = [];

        foreach ($rules as $selector => $declarations) {
            if (str_contains($declarations, 'view-transition-name')) {
                $names[$selector] = $declarations;
            }
        }

        // The bars' names, only while a transition is armed; from the desktop query #bottom-nav is the rail
        self::assertSame([
            'html[data-vt] body > header.site-header' => 'view-transition-name: msp-topbar;',
            'html[data-vt] #bottom-nav' => 'view-transition-name: msp-tabbar;view-transition-name: msp-rail;',
            'html[data-vt] #menu-slot' => 'view-transition-name: msp-menucol;',
        ], $names);
        self::assertMatchesRegularExpression('~@include nav-desktop \{\s*// [^\n]*\n\s*#bottom-nav \{\s*view-transition-name: msp-rail;~', (string) file_get_contents(__DIR__ . '/../' . self::STYLES));
        self::assertStringNotContainsString('site-menu', $code);
    }

    /**
     * One case's answer, an array
     *
     * @return array<mixed>
     */
    private static function answer(string $name): array
    {
        $answer = self::results()[$name] ?? null;
        self::assertIsArray($answer, $name);

        return $answer;
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

        $process = new Process([$node, __DIR__ . '/view-transition-gate-harness.mjs']);
        $process->mustRun();

        /** @var array<string, mixed> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return self::$results = $results;
    }
}
