<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Navigation;

use PHPUnit\Framework\TestCase;

/**
 * Where the navigation launch's styles live (LAUNCH review F7): only the 30-day pointer (.nav-pointer*, on the Hub and in
 * the Menu) rides in app.css, the render-blocking stylesheet of every page. The "What moved" modal - rendered once per
 * player ever - carries its pictures' rules inline, "Where did it go?" links its own Encore entry; both use the bars'
 * own media queries, kept equal to the navigation's tokens here. The Menu page's highlight of the row a "Now" link
 * opens (LAUNCH review Q5) is static and on the `menu` page only.
 */
final class NavigationLaunchStylesTest extends TestCase
{
    private const string ROOT = __DIR__ . '/../../';
    private const string STYLES = self::ROOT . 'assets/styles/';
    private const string MODAL = self::ROOT . 'templates/modals/announcements/_navigation_what_moved.html.twig';
    private const string PAGE = self::ROOT . 'templates/where_did_it_go/index.html.twig';
    private const string PAGE_ENTRY = self::STYLES . 'where_did_it_go.scss';

    public function testAppCssCarriesThePointerOnly(): void
    {
        $imported = $this->importedPartials(self::STYLES . 'app.scss');
        self::assertContains(realpath(self::STYLES . '_navigation-launch.scss'), $imported);
        self::assertGreaterThan(50, count($imported), 'every partial app.scss reaches');

        foreach ($imported as $path) {
            $selectors = implode("\n", array_keys(ScssReader::rules((string) file_get_contents($path))));
            self::assertDoesNotMatchRegularExpression('~\.(?:wm-|wdig(?![a-z]))~', $selectors, basename($path) . ' styles What moved or Where did it go? in app.css');
        }

        $launch = array_keys(ScssReader::rules((string) file_get_contents(self::STYLES . '_navigation-launch.scss')));
        self::assertNotSame([], $launch);

        foreach ($launch as $selector) {
            self::assertStringStartsWith('.nav-pointer', $selector);
        }
    }

    public function testTheModalCarriesItsOwnStyle(): void
    {
        $template = (string) file_get_contents(self::MODAL);
        self::assertSame(1, preg_match('~<style>(.*?)</style>~s', $template, $match));
        $style = (string) preg_replace('~\{#.*?#\}~s', '', $match[1]);
        $markup = str_replace($match[0], '', $template);

        // Every wm- class of the markup has its rule in the modal's own style (a base class through its modifiers: wm-lead)
        preg_match_all('~class="([^"]*)"~', $markup, $classes);
        $used = [];

        foreach ($classes[1] as $list) {
            foreach (preg_split('~\s+~', $list) ?: [] as $class) {
                // A class of its own (not a Twig-built one such as wm-tab--{{ … }})
                if (preg_match('~\Awm-[a-z0-9-]+\z~', $class) === 1) {
                    $used[$class] = true;
                }
            }
        }

        self::assertGreaterThan(20, count($used));

        foreach (array_keys($used) as $class) {
            self::assertMatchesRegularExpression('~\.' . preg_quote($class, '~') . '(?![a-z0-9])~', $style, $class);
        }

        // The desktop media query of the bars, word for word
        self::assertStringContainsString('@media ' . $this->scssString(self::STYLES . '_nav-tokens.scss', 'nav-desktop-media') . '{', $style);
        // Nothing moves in it
        self::assertDoesNotMatchRegularExpression('~transition|animation~', $style);
    }

    public function testWhereDidItGoLinksItsOwnEntry(): void
    {
        self::assertStringContainsString("{% block page_stylesheets %}{{ encore_entry_link_tags('where_did_it_go') }}{% endblock %}", (string) file_get_contents(self::PAGE));
        self::assertStringContainsString(".addStyleEntry('where_did_it_go', './assets/styles/where_did_it_go.scss')", (string) file_get_contents(self::ROOT . 'webpack.config.js'));

        // The bars' media queries, word for word (the entry cannot import the partials that hold them: they print rules)
        self::assertSame($this->scssString(self::STYLES . '_nav-tokens.scss', 'nav-desktop-media'), $this->scssString(self::PAGE_ENTRY, 'wdig-desktop-media'));
        self::assertSame($this->scssString(self::STYLES . '_nav-layout.scss', 'nav-rail-media'), $this->scssString(self::PAGE_ENTRY, 'wdig-rail-media'));

        $rules = ScssReader::rules((string) file_get_contents(self::PAGE_ENTRY));

        foreach (['.wdig-now--phone', '.wdig-now--tablet', '.wdig-now--desktop', '.wdig-now--tablet + .wdig-now--desktop', '.wdig-status'] as $selector) {
            self::assertArrayHasKey($selector, $rules);
        }
    }

    public function testTheMenuPageMarksTheRowItWasOpenedOn(): void
    {
        $rules = ScssReader::rules((string) file_get_contents(self::STYLES . '_nav-menu.scss'));
        $selector = '#site-menu[data-surface="page"] li:target';
        self::assertArrayHasKey($selector, $rules);
        self::assertStringContainsString('var(--nav-active-pill', $rules[$selector]);
        self::assertDoesNotMatchRegularExpression('~transition|animation~', $rules[$selector]);

        // Only there: no other :target rule in the navigation's styles
        foreach (array_keys($rules) as $other) {
            if ($other !== $selector) {
                self::assertStringNotContainsString(':target', $other);
            }
        }
    }

    /**
     * The local partials an entry reaches through @import, recursively (Bootstrap and other packages left out).
     *
     * @param array<string, true> $seen
     * @return list<string>
     */
    private function importedPartials(string $entry, array &$seen = []): array
    {
        preg_match_all('~@import\s+[\'"]([^\'"]+)[\'"]~', ScssReader::withoutComments((string) file_get_contents($entry)), $imports);

        foreach ($imports[1] as $import) {
            $directory = dirname($entry) . '/' . dirname($import) . '/';
            $name = basename($import);

            foreach ([$directory . '_' . $name . '.scss', $directory . $name . '.scss'] as $candidate) {
                $path = realpath($candidate);

                if ($path !== false && !isset($seen[$path])) {
                    $seen[$path] = true;
                    $this->importedPartials($path, $seen);
                }
            }
        }

        return array_keys($seen);
    }

    private function scssString(string $path, string $variable): string
    {
        self::assertSame(1, preg_match('~^\$' . preg_quote($variable, '~') . ':\s*"([^"]+)";~m', (string) file_get_contents($path), $match), $variable);

        return $match[1];
    }
}
