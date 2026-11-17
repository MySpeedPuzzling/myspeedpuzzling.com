<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayDesignSanitizer;
use SpeedPuzzling\Web\Services\CompetitionToolkit\DisplayPresets;
use SpeedPuzzling\Web\Value\DisplayKind;
use SpeedPuzzling\Web\Value\DisplayPreset;
use stdClass;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The browser's display design normaliser (assets/displays/schema.js) under node, by tests/display-schema-harness.mjs:
 * every case of tests/fixtures/display-design-cases.json (the file DisplayDesignSanitizerTest runs the PHP sanitizer
 * over) and every preset as the server normalises it must come out exactly the same - same keys, same order.
 */
final class DisplaySchemaScriptTest extends TestCase
{
    private const string COMPETITION = '018d0004-0000-0000-0000-00000000c0de';

    public function testTheBrowserNormalisesExactlyAsTheServer(): void
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $presets = new DisplayPresets(new DisplayDesignSanitizer());
        $input = [];

        foreach (DisplayPreset::cases() as $preset) {
            foreach (DisplayKind::cases() as $kind) {
                if ($preset->fits($kind)) {
                    $input[$preset->value . '/' . $kind->value] = [
                        'competitionId' => self::COMPETITION,
                        'design' => self::emptyStylesAsObjects($presets->design($preset, $kind, self::COMPETITION)),
                    ];
                }
            }
        }

        $process = new Process([$node, __DIR__ . '/display-schema-harness.mjs']);
        $process->setInput(json_encode(['presets' => $input], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var array<string, string> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        $json = json_decode((string) file_get_contents(__DIR__ . '/fixtures/display-design-cases.json'), true, flags: JSON_THROW_ON_ERROR);
        assert(is_array($json) && is_array($json['cases']));
        self::assertCount(count($json['cases']) + 8, $results);

        foreach ($results as $scenario => $result) {
            self::assertSame('ok', $result, $scenario);
        }
    }

    /**
     * PHP stores an empty `style` / `props` as [] (it cannot tell an empty object from an empty list); the browser
     * writes {} - the same design.
     *
     * @param array<string, mixed> $design
     * @return array<string, mixed>
     */
    private static function emptyStylesAsObjects(array $design): array
    {
        $elements = is_array($design['elements'] ?? null) ? $design['elements'] : [];

        foreach ($elements as $index => $element) {
            assert(is_array($element));

            foreach (['style', 'props'] as $key) {
                if (($element[$key] ?? null) === []) {
                    $element[$key] = new stdClass();
                }
            }

            $elements[$index] = $element;
        }

        $design['elements'] = $elements;

        return $design;
    }
}
