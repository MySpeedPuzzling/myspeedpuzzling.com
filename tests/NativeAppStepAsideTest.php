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
 * item 4): GEST-01 no history entry, GEST-06 the pull never arms, GEST-10 no vibration, GEST-12/17 the view-transition
 * gate stays `none`, GEST-15 the restore decision is `leave`, CARD-01a / MENU-02b the listeners let the link navigate.
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
