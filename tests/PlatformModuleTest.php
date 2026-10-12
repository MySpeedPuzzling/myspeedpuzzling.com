<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * assets/platform.js, the one reader of the platform flags on <html> (docs/features/navigation NAV-14 (b), GEST-09):
 * the native-app flag and the device OS are two different answers, junk values fall back to the web / 'other', and
 * nothing throws without a document. Runs the real module under node (tests/platform-harness.mjs).
 *
 * @phpstan-type Answer array{value?: mixed, threw?: string}
 * @phpstan-type Answers array{name: string, isNativeApp: Answer, nativePlatform: Answer, os: Answer, isStandalone: Answer}
 */
final class PlatformModuleTest extends TestCase
{
    /** @var null|array<string, Answers> */
    private static null|array $results = null;

    /**
     * name => [<html> attributes, classes, isNativeApp, nativePlatform, os, isStandalone]
     */
    private const array ROOTS = [
        'a browser, nothing set yet' => [[], [], false, null, 'other', false],
        'the app on iOS' => [['data-native-app' => 'ios', 'data-os' => 'ios'], [], true, 'ios', 'ios', false],
        'the app on Android' => [['data-native-app' => 'android', 'data-os' => 'android'], [], true, 'android', 'android', false],
        // A browser on an iPhone is iOS and not the app
        'Safari on an iPhone' => [['data-os' => 'ios'], [], false, null, 'ios', false],
        'Chrome on a Pixel' => [['data-os' => 'android'], [], false, null, 'android', false],
        'a desktop' => [['data-os' => 'other'], [], false, null, 'other', false],
        'junk native value counts as the web' => [['data-native-app' => 'windows', 'data-os' => 'ios'], [], false, null, 'ios', false],
        'empty native value counts as the web' => [['data-native-app' => ''], [], false, null, 'other', false],
        'junk OS value is other' => [['data-os' => 'Android'], [], false, null, 'other', false],
        'the installed web app' => [['data-os' => 'android'], ['is-standalone'], false, null, 'android', true],
        // Never standalone inside the app, whatever a class says
        'standalone class inside the app' => [['data-native-app' => 'ios'], ['is-standalone'], true, 'ios', 'other', false],
    ];

    /**
     * @return array<string, array{string, bool, null|string, string, bool}>
     */
    public static function roots(): array
    {
        $rows = [];

        foreach (self::ROOTS as $name => [, , $isNativeApp, $nativePlatform, $os, $isStandalone]) {
            $rows[$name] = [$name, $isNativeApp, $nativePlatform, $os, $isStandalone];
        }

        return $rows;
    }

    #[DataProvider('roots')]
    public function testItReadsTheFlags(string $name, bool $isNativeApp, null|string $nativePlatform, string $os, bool $isStandalone): void
    {
        $answers = $this->answersFor($name);

        self::assertSame(['value' => $isNativeApp], $answers['isNativeApp']);
        self::assertSame(['value' => $nativePlatform], $answers['nativePlatform']);
        self::assertSame(['value' => $os], $answers['os']);
        self::assertSame(['value' => $isStandalone], $answers['isStandalone']);
    }

    public function testWithoutAnythingToReadItIsTheWebAndNothingThrows(): void
    {
        foreach (['no document (node)', 'a null root'] as $name) {
            $answers = $this->answersFor($name);

            self::assertSame(['value' => false], $answers['isNativeApp'], $name);
            self::assertSame(['value' => null], $answers['nativePlatform'], $name);
            self::assertSame(['value' => 'other'], $answers['os'], $name);
            self::assertSame(['value' => false], $answers['isStandalone'], $name);
        }
    }

    /**
     * @return Answers
     */
    private function answersFor(string $name): array
    {
        if (self::$results === null) {
            $cases = [
                ['name' => 'no document (node)', 'noDocument' => true],
                ['name' => 'a null root', 'root' => null],
            ];

            foreach (self::ROOTS as $caseName => [$attributes, $classes]) {
                $cases[] = ['name' => $caseName, 'attributes' => (object) $attributes, 'classes' => $classes];
            }

            $node = new ExecutableFinder()->find('node');
            self::assertIsString($node, 'node is required to execute the module - it is part of the base image');

            $process = new Process([$node, __DIR__ . '/platform-harness.mjs']);
            $process->setInput(json_encode($cases, JSON_THROW_ON_ERROR));
            $process->mustRun();

            /** @var list<Answers> $decoded */
            $decoded = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);
            self::$results = array_column($decoded, null, 'name');
        }

        self::assertArrayHasKey($name, self::$results);

        return self::$results[$name];
    }
}
