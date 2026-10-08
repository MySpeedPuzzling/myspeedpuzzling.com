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
            // Saturated mid-tones where WCAG 2 picked black and APCA picks white, yellows staying black
            '#3d6cf2', '#007bff', '#0d6efd', '#e6194b', '#0082c8', '#d63c42', '#3cb44b', '#f58231',
            '#ffff00', '#ffe119', '#ffc107', '#fff3cd', '#fd7e14', '#ffd700', '#f0e68c',
        ];

        // A colour sweep (steps of 17 = 0x11 on green, 51 on red, 85 on blue), black and white flip inside it
        for ($r = 0; $r <= 255; $r += 51) {
            for ($g = 0; $g <= 255; $g += 17) {
                for ($b = 0; $b <= 255; $b += 85) {
                    $colors[] = sprintf('#%02x%02x%02x', $r, $g, $b);
                }
            }
        }

        // And a finer one around the blues and greys, where the APCA switch lies
        for ($v = 0x30; $v <= 0xb0; $v += 4) {
            $colors[] = sprintf('#%02x%02x%02x', $v, $v, $v);
            $colors[] = sprintf('#%02x%02x%02x', 0x3d, $v, 0xf2);
            $colors[] = sprintf('#%02x%02x%02x', $v, 0x80, 0x40);
        }

        $expected = array_map(static function (string $color): string {
            $chosen = RoundBadgeColor::chosen($color);
            $luminance = $chosen !== null ? RoundBadgeColor::apcaLuminance($chosen) : null;

            return sprintf(
                '%s: chosen %s, text %s, Lc %s',
                $color,
                $chosen ?? 'null',
                RoundBadgeColor::text($color),
                $luminance !== null ? sprintf(
                    '%.4f / %.4f',
                    RoundBadgeColor::apcaContrast(0.0, $luminance),
                    RoundBadgeColor::apcaContrast(1.0, $luminance),
                ) : '-',
            );
        }, $colors);

        $actual = array_map(static fn (string $color, array $result): string => sprintf(
            '%s: chosen %s, text %s, Lc %s',
            $color,
            $result['chosen'] ?? 'null',
            $result['text'],
            $result['lc'] !== null ? sprintf('%.4f / %.4f', $result['lc'][0], $result['lc'][1]) : '-',
        ), $colors, $this->runInNode($colors));

        // The case that started it: white on the mid blue, black stays on yellow
        self::assertStringContainsString('#3d6cf2: chosen #3d6cf2, text #ffffff', implode("\n", $actual));
        self::assertStringContainsString('#ffe119: chosen #ffe119, text #000000', implode("\n", $actual));

        self::assertSame($expected, $actual);
    }

    /**
     * @param list<string> $colors
     * @return list<array{chosen: null|string, text: string, lc: null|array{float, float}}>
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

        /** @var list<array{chosen: null|string, text: string, lc: null|array{float, float}}> $results */
        $results = json_decode($process->getOutput(), true, flags: JSON_THROW_ON_ERROR);

        return $results;
    }
}
