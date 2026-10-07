<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * `hidden` never sits on an element with a Bootstrap display utility (d-flex, d-block, d-grid, …): the utility is
 * `display: … !important` and comes after reboot's `[hidden] { display: none !important }`, so it wins and the element
 * shows although it is hidden (browser verification of PR #136: the seating page showed its "You changed the order"
 * and swap bars and the readiness alert on every load). Put `hidden` on a wrapper instead - the results desk's banners
 * and the seating page's bars do that.
 *
 * Checked: every tag of every template that carries `hidden` (also a Twig-conditional one), and every Stimulus target
 * a controller hides or shows through `.hidden`.
 */
final class HiddenAttributeDisplayUtilityTest extends TestCase
{
    private const string TAG = '/<([a-zA-Z][a-zA-Z0-9-]*)\b((?:[^<>"\']|"[^"]*"|\'[^\']*\')*)>/s';

    private const string DISPLAY_UTILITY = '/^d-(?:(?:sm|md|lg|xl|xxl|print)-)?(?!none$)[a-z-]+$/';

    public function testNoTemplateHidesAnElementWithADisplayUtility(): void
    {
        $offenders = [];

        foreach ($this->templates() as $path => $source) {
            foreach (self::tags($source) as [$line, $attributes]) {
                // Attribute values out of the way: `aria-hidden="true"`, `data-action="…"` never count
                $outsideValues = (string) preg_replace('/"[^"]*"/', '""', $attributes);

                if (preg_match('/(?<![\w.-])hidden(?![\w-])/', $outsideValues) !== 1) {
                    continue;
                }

                $utilities = self::displayUtilities($attributes);

                if ($utilities !== []) {
                    $offenders[] = sprintf('%s:%d (%s)', $path, $line, implode(' ', $utilities));
                }
            }
        }

        self::assertSame([], $offenders, 'Move `hidden` to a wrapper element - the display utility wins over it');
    }

    public function testNoStimulusTargetShownAndHiddenByItsControllerCarriesADisplayUtility(): void
    {
        $hiddenTargets = [];

        foreach (new Finder()->files()->in(__DIR__ . '/../assets/controllers')->name('*_controller.js') as $file) {
            $controller = str_replace('_', '-', $file->getBasename('_controller.js'));
            preg_match_all('/this\.(\w+)Targets?\b[^;\n]*?\.hidden\s*=/', $file->getContents(), $matches);

            foreach ($matches[1] as $target) {
                $hiddenTargets[$controller][$target] = true;
            }
        }

        self::assertNotSame([], $hiddenTargets, 'The scan found no controller at all');

        $offenders = [];

        foreach ($this->templates() as $path => $source) {
            foreach (self::tags($source) as [$line, $attributes]) {
                preg_match_all('/data-([a-z0-9-]+)-target="([^"]+)"/', $attributes, $targets, PREG_SET_ORDER);

                foreach ($targets as [, $controller, $names]) {
                    foreach (preg_split('/\s+/', trim($names)) ?: [] as $name) {
                        if (isset($hiddenTargets[$controller][$name]) && self::displayUtilities($attributes) !== []) {
                            $offenders[] = sprintf('%s:%d (%s target "%s")', $path, $line, $controller, $name);
                        }
                    }
                }
            }
        }

        self::assertSame([], $offenders, 'The controller toggles `hidden` on these - move the display utility to a child element');
    }

    /**
     * @return iterable<string, string>
     */
    private function templates(): iterable
    {
        foreach (new Finder()->files()->in(__DIR__ . '/../templates')->name('*.twig')->sortByName() as $file) {
            yield $file->getRelativePathname() => $file->getContents();
        }
    }

    /**
     * @return list<array{int, string}> line and attribute text of every HTML tag
     */
    private static function tags(string $source): array
    {
        preg_match_all(self::TAG, $source, $matches, PREG_OFFSET_CAPTURE | PREG_SET_ORDER);

        return array_map(
            static fn (array $match): array => [substr_count($source, "\n", 0, $match[0][1]) + 1, $match[2][0]],
            $matches,
        );
    }

    /**
     * @return list<string>
     */
    private static function displayUtilities(string $attributes): array
    {
        if (preg_match('/\bclass="([^"]*)"/', $attributes, $class) !== 1) {
            return [];
        }

        return array_values(array_filter(
            preg_split('/\s+/', trim($class[1])) ?: [],
            static fn (string $name): bool => preg_match(self::DISPLAY_UTILITY, $name) === 1,
        ));
    }
}
