<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The display editor's pure modules under node (competition toolkit D6) - tests/display-editor-harness.mjs: geometry.js
 * (move, resize from each handle, grid + centre snapping, clamping, nudges, the kept ratio of pictures and QR codes,
 * hit test), editor_state.js (add / remove / reorder / hide / lock / duplicate, undo and redo, the save payload equal to
 * normaliseDesign()'s output) and sample_data.js (preview phases, sample results, never a real puzzle).
 * The scenarios live in the harness; every one must report "ok".
 */
final class DisplayEditorScriptTest extends TestCase
{
    public function testEveryScenarioOfTheHarnessPasses(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/display-editor-harness.mjs']);
        $process->setTimeout(300);
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        self::assertGreaterThanOrEqual(30, count($results));

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }
}
