<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Finder\Finder;

/**
 * An `<option data-data='{...}'>` row reaches TomSelect only when the controller sets `dataAttr: 'data'`: TomSelect 2
 * looks the setting up in the option's `dataset`, so the attribute `data-data` is the key `data`. Without it (or with
 * `'data-data'`) the JSON stays one unparsed string - the partner finder gate's picker searched and showed nothing on
 * 2026-10-22, the multiscan picker lost its avatars and `#CODE` search, the brand filters the selected brand's logo.
 */
final class TomSelectDataAttrTest extends TestCase
{
    private const string PROJECT_DIR = __DIR__ . '/..';

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function templatesWithDataData(): iterable
    {
        $templates = (new Finder())->files()->in(self::PROJECT_DIR . '/templates')->name('*.twig')->contains('data-data=');

        foreach ($templates as $template) {
            preg_match_all('/data-controller="([^"]+)"/', $template->getContents(), $matches);

            foreach (array_unique(array_merge(...array_map(static fn (string $value): array => explode(' ', $value), $matches[1]))) as $controller) {
                $script = self::PROJECT_DIR . '/assets/controllers/' . str_replace('-', '_', $controller) . '_controller.js';

                if (is_file($script) && str_contains((string) file_get_contents($script), 'new TomSelect')) {
                    yield $template->getRelativePathname() . ' → ' . $controller => [$template->getRelativePathname(), $script];
                }
            }
        }
    }

    #[DataProvider('templatesWithDataData')]
    public function testTheTomSelectControllerParsesTheDataDataAttribute(string $template, string $script): void
    {
        self::assertMatchesRegularExpression(
            "/dataAttr:\\s*'data'/",
            (string) file_get_contents($script),
            sprintf('%s renders <option data-data=…> for %s - set `dataAttr: \'data\'` or TomSelect never parses it', $template, basename($script)),
        );
    }

    public function testEveryTemplateWithDataDataIsCovered(): void
    {
        self::assertGreaterThanOrEqual(4, iterator_count(self::templatesWithDataData()));
    }

    public function testNoControllerNamesTheAttributeInsteadOfTheDatasetKey(): void
    {
        $scripts = (new Finder())->files()->in(self::PROJECT_DIR . '/assets')->name('*.js')->contains('/dataAttr:\s*[\'"]data-/');

        self::assertCount(0, $scripts, 'dataAttr is a dataset key: `data-data` is \'data\'');
    }
}
