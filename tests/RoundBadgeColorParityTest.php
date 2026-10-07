<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use SpeedPuzzling\Web\Value\RoundBadgeColor;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;

/**
 * The round form's badge preview (assets/round_badge_color.js) must pick the same colours as the event pages
 * (RoundBadgeColor) - or the organiser sees one badge while typing and another one on the page.
 */
final class RoundBadgeColorParityTest extends TestCase
{
    public function testTheBrowserPicksTheColoursThePagesShow(): void
    {
        $colors = [
            ...RoundBadgeColor::PALETTE,
            // Mid-greys around the black/white switch, the old form default, short and upper case forms, garbage
            '#757575', '#767676', '#777777', '#787878', '#7f7f7f', '#808080', '#959595', '#969696',
            '#fe696a', '#FE696A', 'fe696a', '#ffc107', '#0d6efd', '#ABC', 'abc', '  #123456  ', '#1234567',
            'red', '', '#', '#ggg', '#00000', '#ffffff', '#000000', '#000075', '#ff0000', '#00ff00', '#0000ff',
        ];

        // A colour sweep over every channel step of 17 (0x11), black and white flip somewhere inside it
        for ($r = 0; $r <= 255; $r += 51) {
            for ($g = 0; $g <= 255; $g += 17) {
                for ($b = 0; $b <= 255; $b += 85) {
                    $colors[] = sprintf('#%02x%02x%02x', $r, $g, $b);
                }
            }
        }

        $expected = array_map(static fn (string $color): string => sprintf(
            '%s: chosen %s, text %s',
            $color,
            RoundBadgeColor::chosen($color) ?? 'null',
            RoundBadgeColor::text($color),
        ), $colors);

        $actual = array_map(static fn (string $color, array $result): string => sprintf(
            '%s: chosen %s, text %s',
            $color,
            $result['chosen'] ?? 'null',
            $result['text'],
        ), $colors, $this->runInNode($colors));

        self::assertSame($expected, $actual);
    }

    /**
     * @param list<string> $colors
     * @return list<array{chosen: null|string, text: string}>
     */
    private function runInNode(array $colors): array
    {
        $node = new ExecutableFinder()->find('node');

        self::assertIsString($node, 'node is required to execute the script - it is part of the base image');

        $process = new Process([$node, __DIR__ . '/round-badge-color-harness.mjs']);
        $process->setInput((string) json_encode([
            'colors' => $colors,
            'roundFormDefault' => RoundBadgeColor::ROUND_FORM_DEFAULT,
        ], JSON_THROW_ON_ERROR));
        $process->mustRun();

        /** @var list<array{chosen: null|string, text: string}> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $results;
    }
}
