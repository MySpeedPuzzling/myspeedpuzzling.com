<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Inside the Hotwire Native app every web-only navigation behaviour steps aside (docs/features/navigation NAV-14 (b)):
 * one row per native-aware module, its pure decision run with the native flag on and off
 * (tests/native-step-aside-harness.mjs). Each bar, layer and gesture item adds its row (implementation plan §3.5
 * item 4): GEST-01 no history entry, PROF-05c no Results entry either, GEST-06 the pull never arms, GEST-10 no
 * vibration, GEST-12/17 the view-transition gate stays `none`, GEST-15 the restore decision is `leave`, CARD-01a /
 * MENU-02b the listeners let the link navigate, GEST-13 the confirm watchdog reports nothing, GEST-03 a tab tap
 * navigates, GEST-18 no long-press label.
 *
 * @phpstan-type Answer array{value?: mixed, threw?: string}
 * @phpstan-type Row array{id: string, app: Answer, web: Answer}
 */
final class NativeAppStepAsideTest extends TestCase
{
    /**
     * module => [its answer inside the app, its answer on the web]
     */
    private const array ROWS = [
        // NAV-14 foundation: the flags (the same Android phone, inside the app and in Chrome)
        'assets/platform.js' => [
            ['isNativeApp' => true, 'nativePlatform' => 'android', 'os' => 'android'],
            ['isNativeApp' => false, 'nativePlatform' => null, 'os' => 'android'],
        ],
        // GEST-01 (f): inside the app no entry is pushed and a layer closes without history.back()
        'assets/layer_history.js' => [
            ['open' => 'none', 'openOverAnotherLayer' => 'none', 'close' => 'direct'],
            ['open' => 'push', 'openOverAnotherLayer' => 'swap', 'close' => 'back'],
        ],
        // GEST-13: the confirm watchdog reports nothing inside the app; on the web only an iPhone's refusal
        'assets/confirm_dialog.js' => [
            ['refusedOnAnIphone' => false, 'refusedOnThisDevice' => false],
            ['refusedOnAnIphone' => true, 'refusedOnThisDevice' => false],
        ],
        // MENU-02b / NAV-13: a link to `menu` (or the ☰) goes to the page inside the app (the app's Menu screen); on the
        // web it opens the phone panel or the desktop overlay, focuses the docked column, and the ☰ docks / folds it
        'assets/navigation/menu_links.js' => [
            ['phone' => 'page', 'desktop' => 'page', 'docked' => 'page', 'burgerFrom1280' => 'page'],
            ['phone' => 'panel', 'desktop' => 'overlay', 'docked' => 'focus', 'burgerFrom1280' => 'toggle'],
        ],
        // NAV-13: the desktop ☰'s dock / fold state is the web's; inside the app it stays a plain opener
        'assets/navigation/nav_layout.js' => [
            ['menuButtonAt1440' => null],
            ['menuButtonAt1440' => 'fold'],
        ],
        // NAV-03: the top bar may hide on a phone on the web, never inside the app
        'assets/topbar_scroll.js' => [
            ['scrolledDownOnAPhone' => false],
            ['scrolledDownOnAPhone' => true],
        ],
        // PROF-05c: the Results page writes no history entry inside the app, takes nothing over, restores no scroll
        'assets/player_results_state.js' => [
            ['sortChange' => 'none', 'showMore' => 'none', 'takesOver' => false, 'backByFullLoad' => 'none'],
            ['sortChange' => 'push', 'showMore' => 'replace', 'takesOver' => true, 'backByFullLoad' => 'restore'],
        ],
        // CARD-01a: a tap on a player's name opens the card on the web; inside the app the link goes to the profile
        'assets/player_card.js' => [
            ['tapOnAName' => 'profile'],
            ['tapOnAName' => 'card'],
        ],
        // GEST-03: a tap on the active tab scrolls to the top / refreshes the Hub's feed on the web; the app has no web
        // tab bar - the link navigates
        'assets/tab_reselect.js' => [
            ['hubScrolled' => 'navigate', 'hubAtTheTop' => 'navigate'],
            ['hubScrolled' => 'scroll-top', 'hubAtTheTop' => 'refresh-feed'],
        ],
        // GEST-06: the pull never arms inside the app; on the web only in the installed app, never in a browser tab
        'assets/pull_to_refresh.js' => [
            ['installedApp' => false, 'browserTab' => false],
            ['installedApp' => true, 'browserTab' => false],
        ],
        // GEST-10: no vibration inside the app (the haptics backend is none there) - the online round's alerts neither
        // (Platform feel review Q1); on the web an Android phone plays
        'assets/haptics.js' => [
            ['timeSaved' => false, 'switchOn' => false, 'onlineAlert' => false],
            ['timeSaved' => true, 'switchOn' => true, 'onlineAlert' => true],
        ],
        // GEST-18: the long-press label belongs to the web tab bar - none inside the app
        'assets/tab_long_press.js' => [
            ['heldOnACutTab' => false],
            ['heldOnACutTab' => true],
        ],
        // GEST-12 / GEST-17: the view-transition gate stays `none` inside the app; Chrome on the same phone fades through
        // on a place tap and morphs a card's thumbnail
        'assets/view_transition_gate.js' => [
            ['placeTap' => ['meta' => 'none', 'vt' => null], 'cardTap' => ['meta' => 'none', 'vt' => null]],
            ['placeTap' => ['meta' => 'same-origin', 'vt' => 'place'], 'cardTap' => ['meta' => 'same-origin', 'vt' => 'morph']],
        ],
        // GEST-14 / GEST-15: the restore is the native adapter's inside the app; a bfcache copy reloads as before
        'assets/instant_back.js' => [
            ['backToAFreshSnapshot' => 'leave', 'backForwardCache' => 'reload'],
            ['backToAFreshSnapshot' => 'snapshot', 'backForwardCache' => 'keep'],
        ],
    ];

    /** @var null|array<string, Row> */
    private static null|array $results = null;

    /**
     * @return array<string, array{string, mixed, mixed}>
     */
    public static function rows(): array
    {
        $rows = [];

        foreach (self::ROWS as $module => [$inApp, $onWeb]) {
            $rows[$module] = [$module, $inApp, $onWeb];
        }

        return $rows;
    }

    #[DataProvider('rows')]
    public function testTheModuleStepsAsideInsideTheAppOnly(string $module, mixed $inApp, mixed $onWeb): void
    {
        $row = $this->results()[$module] ?? null;

        self::assertNotNull($row, $module . ' has no row in tests/native-step-aside-harness.mjs');
        self::assertSame(['value' => $inApp], $row['app'], $module . ' inside the app');
        self::assertSame(['value' => $onWeb], $row['web'], $module . ' on the web');
        self::assertFileExists(__DIR__ . '/../' . $module);
    }

    public function testEveryHarnessRowHasItsExpectedAnswers(): void
    {
        self::assertSame(array_keys(self::ROWS), array_keys($this->results()));
    }

    /**
     * @return array<string, Row>
     */
    private function results(): array
    {
        if (self::$results === null) {
            $node = new ExecutableFinder()->find('node');
            self::assertIsString($node, 'node is required to execute the modules - it is part of the base image');

            $process = new Process([$node, __DIR__ . '/native-step-aside-harness.mjs']);
            $process->mustRun();

            /** @var list<Row> $decoded */
            $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::$results = array_column($decoded, null, 'id');
        }

        return self::$results;
    }
}
