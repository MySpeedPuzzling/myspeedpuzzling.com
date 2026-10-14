<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use PHPUnit\Framework\TestCase;

/**
 * The bars' icons are Bootstrap Icons' own paths (templates/navigation/_icons.html.twig): the sprite writes them
 * compactly - an arc's two flags and a repeated command's letter without separators (the chrome's weight, README
 * §4.12) - and every parser reads the same path from both. This test reads both the way the SVG path grammar does
 * (a flag is one character, coordinates after a moveto are linetos) and compares the segments number by number with
 * the published files, so a hand edit, a rounded number or an icon of another version fails here.
 */
final class SpritePathsTest extends TestCase
{
    private const string SPRITE = __DIR__ . '/../../templates/navigation/_icons.html.twig';
    private const string ICONS = __DIR__ . '/../../node_modules/bootstrap-icons/icons/';

    /** Arguments per command */
    private const array ARGUMENTS = ['M' => 2, 'L' => 2, 'H' => 1, 'V' => 1, 'C' => 6, 'S' => 4, 'Q' => 4, 'T' => 2, 'A' => 7, 'Z' => 0];

    public function testEverySymbolDrawsThePublishedIcon(): void
    {
        if (!is_dir(self::ICONS)) {
            self::markTestSkipped('node_modules/bootstrap-icons is not installed.');
        }

        preg_match_all('~<symbol id="nav-i-([a-z0-9-]+)" viewBox="0 0 16 16">(.*?)</symbol>~s', (string) file_get_contents(self::SPRITE), $symbols, PREG_SET_ORDER);
        self::assertGreaterThanOrEqual(18, count($symbols), 'The sprite\'s symbols');

        foreach ($symbols as [, $name, $body]) {
            $published = (string) file_get_contents(self::ICONS . $name . '.svg');
            self::assertSame('', trim((string) preg_replace('~<path[^>]*/>~', '', $body)), $name . ': paths only');
            self::assertSame($this->paths($published), $this->paths($body), $name . ': the published icon');
        }
    }

    public function testThePathReaderReadsTheCompactForms(): void
    {
        self::assertSame($this->segments('M1 2L3 4L5 6'), $this->segments('M1 2 3 4 5 6'), 'implicit linetos after a moveto');
        self::assertSame($this->segments('m1 2l3 4l5 6'), $this->segments('m1 2 3 4 5 6'));
        self::assertSame($this->segments('a.5.5 0 0 0-.708 0a.5.5 0 0 1 1.5 7.5'), $this->segments('a.5.5 0 00-.708 0 .5.5 0 011.5 7.5'), 'flags without separators');
        self::assertNotSame($this->segments('a.5.5 0 0 1 1.5 7.5'), $this->segments('a.5.5 0 0 0 1.5 7.5'));
    }

    /**
     * @return list<array{string, list<array{string, string}>}> each <path>: its other attributes and its segments
     */
    private function paths(string $svg): array
    {
        preg_match_all('~<path((?:\s+(?!d=)[a-z-]+="[^"]*")*)\s+d="([^"]+)"\s*/>~', $svg, $matches, PREG_SET_ORDER);

        return array_map(fn (array $path): array => [trim($path[1]), $this->segments($path[2])], $matches);
    }

    /**
     * The path as the grammar reads it: one entry per command instance (implicit repeats spelled out), numbers as floats
     *
     * @return list<array{string, string}>
     */
    private function segments(string $data): array
    {
        $segments = [];
        $command = null;
        $position = 0;
        $length = strlen($data);

        while (true) {
            $position += strspn($data, " ,\t\n\r", $position);

            if ($position >= $length) {
                break;
            }

            $char = $data[$position];

            if (array_key_exists(strtoupper($char), self::ARGUMENTS)) {
                $command = $char;
                $position++;

                if (strtoupper($char) === 'Z') {
                    $segments[] = [$char, ''];

                    continue;
                }
            } else {
                self::assertNotNull($command, 'A path starts with a command: ' . $data);
                self::assertNotSame('Z', strtoupper($command), 'Numbers after closepath: ' . $data);
                $command = match ($command) {
                    'M' => 'L',
                    'm' => 'l',
                    default => $command,
                };
            }

            $arguments = [];

            for ($index = 0; $index < self::ARGUMENTS[strtoupper($command)]; $index++) {
                $position += strspn($data, " ,\t\n\r", $position);

                if (strtoupper($command) === 'A' && ($index === 3 || $index === 4)) {
                    self::assertContains($data[$position] ?? '', ['0', '1'], 'An arc flag: ' . $data);
                    $arguments[] = $data[$position];
                    $position++;

                    continue;
                }

                self::assertSame(1, preg_match('~[+-]?(?:\d+\.?\d*|\.\d+)(?:[eE][+-]?\d+)?~A', $data, $number, 0, $position), 'A number at ' . $position . ': ' . $data);
                $arguments[] = (string) (float) $number[0];
                $position += strlen($number[0]);
            }

            $segments[] = [$command, implode(' ', $arguments)];
        }

        return $segments;
    }
}
