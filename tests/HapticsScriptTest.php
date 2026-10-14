<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * Haptics (docs/features/navigation README §4.13 G10, §4.14 (c); requirement GEST-10): assets/haptics.js run under node
 * (tests/haptics-harness.mjs). haptic(name) plays only on an Android phone (data-os), outside the native app, with the
 * device's Vibration setting not off, the page visible, the user having interacted and the API present - the decision
 * matrix os × native × setting × visibility × activation × API has exactly two playing cells (activation true or not
 * reported); the pattern table; never throws (a throwing vibrate or storage, no env at all); a storage that refuses still
 * lets the switch turn haptics off for the page. signal() - the online round's alerts - keeps its own patterns and plays
 * wherever the web's API exists, never inside the app. install() turns a user's switch toggle into
 * toggleOn / toggleOff, a refused Turbo form (422) into reject and a successful submit of a form naming its haptic into
 * that haptic.
 */
final class HapticsScriptTest extends TestCase
{
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
            'PATTERNS' => [
                'confirm' => 20,
                'reject' => [30, 100, 30],
                'tick' => 10,
                'toggleOn' => 10,
                'toggleOff' => null,
                'longPress' => 30,
            ],
            'SETTING_KEY' => 'msp:haptics',
            'SETTING_ON' => 'on',
            'SETTING_OFF' => 'off',
            'ON_SUCCESS_ATTRIBUTE' => 'data-haptic-on-success',
            'SWITCH_SELECTOR' => 'input[type="checkbox"][switch]',
        ], self::results()['constants']);
    }

    /** README §4.13 G10: never more than 40 ms for a single pulse */
    public function testNoPulseIsLongerThan40Milliseconds(): void
    {
        /** @var array{PATTERNS: array<string, int|list<int>|null>} $constants */
        $constants = self::results()['constants'];

        foreach ($constants['PATTERNS'] as $name => $pattern) {
            $pulses = is_array($pattern) ? array_values(array_filter($pattern, static fn (int $index): bool => $index % 2 === 0, ARRAY_FILTER_USE_KEY)) : [$pattern ?? 0];

            foreach ($pulses as $pulse) {
                self::assertLessThanOrEqual(40, $pulse, $name);
            }
        }
    }

    public function testOnlyAnAndroidPhoneOutsideTheAppWithEveryRuleHoldingPlays(): void
    {
        self::assertSame([
            'total' => 3 * 2 * 2 * 2 * 3 * 2,
            'plays' => [
                ['os' => 'android', 'native' => false, 'setting' => 'on', 'visibility' => 'visible', 'hasBeenActive' => true, 'api' => true],
                // A browser without navigator.userActivation: only a reported "never active" stops it
                ['os' => 'android', 'native' => false, 'setting' => 'on', 'visibility' => 'visible', 'hasBeenActive' => 'unknown', 'api' => true],
            ],
        ], self::results()['matrix']);
    }

    public function testThePatternTable(): void
    {
        self::assertSame([
            'confirm' => ['returned' => true, 'played' => [20]],
            'reject' => ['returned' => true, 'played' => [[30, 100, 30]]],
            'tick' => ['returned' => true, 'played' => [10]],
            'toggleOn' => ['returned' => true, 'played' => [10]],
            // Named for a later native bridge: nothing on the web
            'toggleOff' => ['returned' => false, 'played' => []],
            'longPress' => ['returned' => true, 'played' => [30]],
        ], self::results()['patterns played']);

        self::assertSame(['returned' => false, 'played' => [], 'patternOf' => null], self::results()['an unknown name plays nothing']);
        self::assertSame([30, 100, 30], self::results()['the reject pattern handed over is a copy']);
    }

    public function testItNeverThrows(): void
    {
        self::assertFalse(self::results()['a vibrate that throws']);
        self::assertFalse(self::results()['no env at all (node: no navigator, no document)']);
        self::assertSame([false, false, false], self::results()['a junk env']);
        self::assertSame(['web' => 'web', 'native' => 'none', 'noApi' => 'none', 'none' => 'none'], self::results()['backend']);
    }

    public function testTheDeviceSetting(): void
    {
        self::assertSame([
            'missing' => 'on',
            'off' => 'off',
            'on' => 'on',
            'junk' => 'on',
            'throwing' => 'on',
            'noStorage' => 'on',
        ], self::results()['readSetting']);

        self::assertSame([
            'off' => [true, 'off'],
            'on' => [true, 'on'],
            'throwing' => false,
            'noStorage' => false,
            'keptAgain' => [true, 'on', 'on'],
        ], self::results()['writeSetting']);
    }

    /**
     * Platform feel review F5 (README §4.13 G10: the setting must be able to turn haptics off): a storage that refuses -
     * site data blocked, no storage at all, a full one holding "on" - keeps the switch's value for the page, in memory;
     * the next haptic follows it. A write the storage keeps again hands the decision back to the storage.
     */
    public function testARefusingStorageStillTurnsHapticsOffForThePage(): void
    {
        self::assertSame([
            'blocked' => [
                'before' => true,
                'turnedOff' => false,
                'read' => ['off', 'off', 'off'],
                'afterOff' => false,
                'turnedOn' => false,
                'afterOn' => true,
            ],
            'none' => ['turnedOff' => false, 'afterOff' => false],
            'full' => ['turnedOff' => false, 'read' => 'off', 'afterOff' => false],
            'keptAgain' => ['turnedOn' => true, 'stored' => 'on', 'read' => 'on', 'plays' => true],
            'played' => [20, 20, 20],
        ], self::results()['a refusing storage']);
    }

    public function testTheBrowserIsReadThroughThePlatformModule(): void
    {
        self::assertSame([
            'env' => ['os' => 'android', 'native' => false, 'setting' => 'on', 'visibility' => 'visible', 'hasBeenActive' => true, 'vibrate' => 'function'],
            'returned' => true,
            'played' => [10],
            'native' => true,
            'ios' => 'ios',
            'hidden' => 'hidden',
            'notActive' => false,
            'noActivationApi' => true,
            'settingOff' => 'off',
            'throwingStorage' => 'on',
            'noVibrate' => null,
        ], self::results()['browserEnv']);

        // navigator.vibrate throws "Illegal invocation" when called without navigator as its receiver
        self::assertSame(['returned' => true, 'calls' => [30]], self::results()['the vibrate call keeps navigator as its receiver']);
    }

    /**
     * The online round's alerts (assets/online/beeps.js) keep their patterns and the rule they always had on the web: the
     * API - whatever the device, the Vibration setting, visibility or activation say. Through the one backend, so never
     * inside the app (README §4.14 (b)/(c), Platform feel review Q1)
     */
    public function testSignalsPlayWhereverTheWebApiExists(): void
    {
        self::assertSame([
            'iosDesktopSettingOffHiddenInactive' => [true, true],
            'insideTheApp' => [false, false, false],
            'browserTab' => true,
            'played' => [[250], [400, 150, 400, 150, 700], [700]],
            'noApi' => false,
            'throwing' => false,
            'noEnv' => false,
        ], self::results()['signal']);
    }

    public function testTheFormSubmitRule(): void
    {
        self::assertSame([
            'refused' => 'reject',
            'refusedWithoutName' => 'reject',
            'saved' => 'confirm',
            'savedNoName' => null,
            'savedJunkName' => null,
            'serverError' => null,
            'networkError' => null,
        ], self::results()['submitHaptic']);
    }

    /**
     * A user's switch toggle (trusted change on input[switch]) → toggleOn / toggleOff; a script's or another checkbox's →
     * nothing; a refused Turbo form → reject; a form naming its haptic → it on success, nothing on a 500 or a network
     * error; installed once
     */
    public function testThePageWideListeners(): void
    {
        self::assertSame([
            'listeners' => ['change' => 1, 'turbo:submit-end' => 1],
            'played' => ['toggleOn', 'toggleOff', 'reject', 'confirm'],
        ], self::results()['install']);
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

        $process = new Process([$node, __DIR__ . '/haptics-harness.mjs']);
        $process->mustRun();

        /** @var array<string, mixed> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return self::$results = $results;
    }
}
