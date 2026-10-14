<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Tests\Navigation\ScssReader;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The installed app's pull indicator per platform (docs/features/navigation README §4.13 G17, requirement GEST-19):
 * assets/pull_indicator.js run under node (tests/pull-indicator-harness.mjs) - Android and other Material's 40 px disc
 * with the arrow (flipping at GEST-06's threshold, unchanged: ≈ 128 px of finger travel), iOS a bare spinner without an
 * arrow element, fading (opacity = progress) and turning (progress × 270°) with the pull. The stylesheet the controller
 * injected before now lives in assets/styles/_pull-to-refresh.scss (pinned below). The browser part (standalone forced:
 * iOS UA → is-ios, transparent, no arrow; Pixel → 40 px disc + arrow) is checks/GEST-19.py.
 */
final class PullIndicatorScriptTest extends TestCase
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
        self::assertSame(
            ['INDICATOR_SIZE' => 40, 'INDICATOR_OVERSHOOT' => 20, 'SPINNER_TURN_DEGREES' => 270, 'DISC' => 'disc', 'SPINNER' => 'spinner', 'IOS_CLASS' => 'is-ios'],
            self::results()['constants'],
        );
        self::assertSame(['ios' => 'spinner', 'android' => 'disc', 'other' => 'disc', 'junk' => 'disc'], self::results()['lookFor']);
        self::assertSame(['disc' => 'pwa-ptr-indicator', 'spinner' => 'pwa-ptr-indicator is-ios'], self::results()['indicatorClass']);
    }

    /** iOS has no arrow element at all; the disc keeps its arrow and spinner */
    public function testTheMarkupPerPlatform(): void
    {
        $markup = self::results()['markup'];
        self::assertIsArray($markup);

        self::assertSame(['arrow' => 1, 'spinner' => 1, 'ios' => 0], $markup['disc']);
        self::assertSame(['arrow' => 0, 'spinner' => 0, 'ios' => 1, 'spokes' => 8], $markup['spinner']);
        // The leading spoke is the brightest, the trail fades behind it
        self::assertSame([1, 0.23, 0.34, 0.45, 0.56, 0.67, 0.78, 0.89], $markup['spokeOpacities']);
    }

    /** The disc as it always was, 40 px instead of 36: grows from .3, fades in at 1.5 × the progress, the arrow flips at the threshold */
    public function testTheDiscFollowsThePull(): void
    {
        self::assertSame([
            '0' => ['transform' => 'translateY(-20px) scale(0.3)', 'opacity' => 0, 'arrow' => 'rotate(0deg)', 'spinner' => null, 'past' => false],
            '32' => ['transform' => 'translateY(-4px) scale(0.475)', 'opacity' => 0.375, 'arrow' => 'rotate(0deg)', 'spinner' => null, 'past' => false],
            '120' => ['transform' => 'translateY(40px) scale(0.956)', 'opacity' => 1, 'arrow' => 'rotate(0deg)', 'spinner' => null, 'past' => false],
            '128' => ['transform' => 'translateY(44px) scale(1)', 'opacity' => 1, 'arrow' => 'rotate(180deg)', 'spinner' => null, 'past' => true],
            '136' => ['transform' => 'translateY(48px) scale(1)', 'opacity' => 1, 'arrow' => 'rotate(180deg)', 'spinner' => null, 'past' => true],
            '300' => ['transform' => 'translateY(64px) scale(1)', 'opacity' => 1, 'arrow' => 'rotate(180deg)', 'spinner' => null, 'past' => true],
        ], self::results()['disc frames']);
    }

    /** iOS: no scale, opacity = the progress, the spinner turns progress × 270° - and the same threshold as the disc */
    public function testTheIosSpinnerFadesAndTurnsWithThePull(): void
    {
        self::assertSame([
            '0' => ['transform' => 'translateY(-20px)', 'opacity' => 0, 'arrow' => null, 'spinner' => 'rotate(0deg)', 'past' => false],
            '32' => ['transform' => 'translateY(-4px)', 'opacity' => 0.25, 'arrow' => null, 'spinner' => 'rotate(67.5deg)', 'past' => false],
            '120' => ['transform' => 'translateY(40px)', 'opacity' => 0.938, 'arrow' => null, 'spinner' => 'rotate(253.125deg)', 'past' => false],
            '128' => ['transform' => 'translateY(44px)', 'opacity' => 1, 'arrow' => null, 'spinner' => 'rotate(270deg)', 'past' => true],
            '136' => ['transform' => 'translateY(48px)', 'opacity' => 1, 'arrow' => null, 'spinner' => 'rotate(270deg)', 'past' => true],
            '300' => ['transform' => 'translateY(64px)', 'opacity' => 1, 'arrow' => null, 'spinner' => 'rotate(270deg)', 'past' => true],
        ], self::results()['spinner frames']);
    }

    /** The styles moved out of the controller: the disc 40 px under the top bar, iOS transparent without a shadow, spinning in steps */
    public function testTheStylesheet(): void
    {
        $rules = ScssReader::rules(ScssReader::withoutComments((string) file_get_contents(__DIR__ . '/../assets/styles/_pull-to-refresh.scss')));
        $indicator = $rules['.pwa-ptr-indicator'] ?? '';

        foreach (['position: fixed;', 'top: var(--topbar-h, 0px);', 'z-index: $z-nav-column;', 'width: $pwa-ptr-size;', 'height: $pwa-ptr-size;', 'border-radius: 50%;', 'background: #e9ecef;', 'pointer-events: none;'] as $declaration) {
            self::assertStringContainsString($declaration, $indicator);
        }

        self::assertStringContainsString('$pwa-ptr-size: 40px;', (string) file_get_contents(__DIR__ . '/../assets/styles/_pull-to-refresh.scss'));
        self::assertSame('background: transparent;box-shadow: none;', $rules['.pwa-ptr-indicator.is-ios'] ?? null);
        self::assertSame('animation: pwa-ptr-spin .8s steps(8) infinite;', $rules['.pwa-ptr-indicator.is-ios.is-refreshing .pwa-ptr-ios-spinner'] ?? null);

        // The controller builds the indicator from the module and injects no style
        $controller = (string) file_get_contents(__DIR__ . '/../assets/controllers/pwa_lifecycle_controller.js');
        self::assertStringContainsString("import { indicatorClass, indicatorMarkup, INDICATOR_SIZE, lookFor, pullFrame } from '../pull_indicator.js';", $controller);
        self::assertStringContainsString('this._look = lookFor(os());', $controller);
        self::assertStringNotContainsString('pwa-ptr-styles', $controller);
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

        $process = new Process([$node, __DIR__ . '/pull-indicator-harness.mjs']);
        $process->mustRun();

        /** @var array<string, mixed> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return self::$results = $results;
    }
}
