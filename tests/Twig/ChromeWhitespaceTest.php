<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Twig;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Twig\ChromeWhitespace;
use SpeedPuzzling\Web\Twig\ChromeWhitespaceTwigExtension;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Twig\Environment;
use Twig\Loader\ArrayLoader;

/**
 * The chrome templates lose their indentation at compile time (SpeedPuzzling\Web\Twig\ChromeWhitespace, README §4.12
 * "Weight"): a whitespace run holding a line break becomes one line break, the indentation at either end of a text
 * node one space - only in the listed templates' own text, never in what an expression prints. The browser reads
 * both the same as long as no listed template puts literal text into whitespace-preserving markup.
 */
final class ChromeWhitespaceTest extends WebTestCase
{
    /**
     * Classes of whitespace-preserving rules (white-space: pre*) a listed template may carry, with the reason
     */
    private const array PRE_ALLOWED = [
        'msp-confirm-message' => 'base.html.twig: an empty <p> assets/confirm_dialog.js fills with the question',
    ];

    /**
     * What the collapse would change in a listed template's own text, with the reason: whitespace the browser keeps,
     * or literal text Twig hands on as data instead of HTML. Twig comments are left out before matching.
     */
    private const array WHITESPACE_SENSITIVE = [
        '~<pre\b~i' => '<pre> shows its whitespace',
        '~<textarea\b~i' => '<textarea> keeps its whitespace as the value',
        '~style="[^"]*white-space~i' => 'an inline white-space style',
        '~\\\\\n~' => 'a line continuation in an inline script string',
        '~<script\b[^>]*>(?:(?!</script>).)*`~is' => 'a JS template literal in an inline script keeps its line breaks and indentation',
        '~\{%-?\s*trans\b~' => 'a {% trans %} body is the message id: collapsed, it is a key translation:extract never wrote',
        '~\{%-?\s*set\s+\w+\s*-?%\}~' => 'a {% set %} capture is data (a JSON attribute, a compared string)',
        '~\{%-?\s*(verbatim|raw)\b~' => 'a {% verbatim %} body is printed as written',
        '~\{%-?\s*apply\b~' => 'an {% apply %} body is filtered as data (json_encode, url_encode, ...)',
    ];

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function texts(): iterable
    {
        yield 'indentation after a line break' => ["<div>\n        <a>", "<div>\n<a>"];
        yield 'blank lines and trailing spaces' => ["</a>   \n\n    \n  <b>", "</a>\n<b>"];
        yield 'indentation left after a block tag' => ['        <li>', ' <li>'];
        yield 'indentation before a block tag' => ['</li>        ', '</li> '];
        yield 'spaces inside a line stay' => ['<i></i>  Deutsch', '<i></i>  Deutsch'];
        yield 'a single space stays' => [' <span>', ' <span>'];
        yield 'a script line comment still ends' => ["// a comment\n            var x = 1;", "// a comment\nvar x = 1;"];
        yield 'nothing' => ['', ''];
    }

    #[DataProvider('texts')]
    public function testCollapse(string $text, string $expected): void
    {
        self::assertSame($expected, ChromeWhitespace::collapse($text));
    }

    public function testTheListedTemplates(): void
    {
        self::assertTrue(ChromeWhitespace::appliesTo('base.html.twig'));
        self::assertTrue(ChromeWhitespace::appliesTo('navigation/_topbar.html.twig'));
        self::assertTrue(ChromeWhitespace::appliesTo('components/GlobalSearch.html.twig'));
        self::assertTrue(ChromeWhitespace::appliesTo('_footer.html.twig'));
        self::assertFalse(ChromeWhitespace::appliesTo('faq.html.twig'));
        self::assertFalse(ChromeWhitespace::appliesTo('navigation.html.twig'));
        self::assertFalse(ChromeWhitespace::appliesTo('components/XpRing.html.twig'));
        self::assertFalse(ChromeWhitespace::appliesTo('_player_avatar.html.twig'));
        // The one shared partial on the list: the page search's camera renders it on every member page (ChromeWhitespace)
        self::assertTrue(ChromeWhitespace::appliesTo('_barcode_scanner_hud.html.twig'));
        self::assertFalse(ChromeWhitespace::appliesTo('_barcode_scanner_panel.html.twig'));
        self::assertFalse(ChromeWhitespace::appliesTo(''));

        // Every listed template exists (a rename must not drop it silently)
        foreach (ChromeWhitespace::TEMPLATES as $name) {
            self::assertTrue(str_ends_with($name, '/') ? is_dir($this->templates() . $name) : is_file($this->templates() . $name), $name);
        }
    }

    public function testOnlyTheListedTemplatesOwnTextChanges(): void
    {
        $source = "<div class=\"a\n           b\">\n    {{ value }}\n    <span>  x  y  </span>\n    {% if true %}\n        <li>\n    {% endif %}\n</div>";
        $twig = new Environment(new ArrayLoader([
            'navigation/_bar.html.twig' => $source,
            'page.html.twig' => $source,
        ]));
        $twig->addExtension(new ChromeWhitespaceTwigExtension());
        $value = "printed\n        as is";

        self::assertSame(
            "<div class=\"a\nb\">\nprinted\n        as is\n<span>  x  y  </span>\n <li>\n</div>",
            $twig->render('navigation/_bar.html.twig', ['value' => $value]),
        );
        self::assertSame(
            (new Environment(new ArrayLoader(['page.html.twig' => $source])))->render('page.html.twig', ['value' => $value]),
            $twig->render('page.html.twig', ['value' => $value]),
        );
    }

    /**
     * Twig recompiles a cached template only when an extension's file is newer than it: a change to the visitor
     * (the template list, the collapse) must count, or dev/test caches keep the old output until a cache:clear
     */
    public function testAChangeToTheVisitorRecompilesTheTemplates(): void
    {
        $visitor = (string) (new \ReflectionClass(ChromeWhitespace::class))->getFileName();
        $original = filemtime($visitor);
        self::assertIsInt($original);
        $changed = max(time(), $original, (new ChromeWhitespaceTwigExtension())->getLastModified()) + 3600;

        try {
            self::assertTrue(touch($visitor, $changed));
            clearstatcache(true, $visitor);

            $twig = new Environment(new ArrayLoader(['navigation/_bar.html.twig' => '<b>x</b>']));
            $twig->addExtension(new ChromeWhitespaceTwigExtension());

            self::assertFalse($twig->isTemplateFresh('navigation/_bar.html.twig', $changed - 1), 'Compiled before the visitor changed: stale');
            self::assertTrue($twig->isTemplateFresh('navigation/_bar.html.twig', $changed));
        } finally {
            touch($visitor, $original);
            clearstatcache(true, $visitor);
        }
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function whitespaceSensitiveSnippets(): iterable
    {
        yield 'pre' => ['<pre>a  b</pre>'];
        yield 'textarea' => ['<textarea name="a"></textarea>'];
        yield 'inline white-space style' => ['<p style="color: red; white-space: pre-line">'];
        yield 'line continuation' => ["<script>var a = 'x\\\n    y';</script>"];
        yield 'template literal in an inline script' => ["<script type=\"module\">\nvar a = `x\n    y`;\n</script>"];
        yield 'trans block' => ['{% trans %}Hello  {{ name }}{% endtrans %}'];
        yield 'trans block with a domain' => ['{%- trans from "nav" %}Hello{% endtrans %}'];
        yield 'set capture' => ['{%- set label -%}<b>x</b>{% endset %}'];
        yield 'verbatim' => ['{% verbatim %}{{ x }}{% endverbatim %}'];
        yield 'apply' => ['{% apply json_encode %}a  b{% endapply %}'];
    }

    #[DataProvider('whitespaceSensitiveSnippets')]
    public function testTheGuardSeesWhatTheCollapseWouldChange(string $snippet): void
    {
        self::assertCount(1, self::whitespaceSensitive($snippet), $snippet);
    }

    public function testTheGuardLetsTheRestThrough(): void
    {
        foreach (
            [
            "{% set label = 'x' %}",
            "{% set label = 'x' -%}",
            "{{ 'menu.title'|trans }}",
            "{% trans_default_domain 'nav' %}",
            '<script>var a = 1;</script><p>`code` outside a script</p>',
            '{# a `template literal`, {% trans %}, <pre> in a comment #}',
            '<p class="pre-line">',
            ] as $snippet
        ) {
            self::assertSame([], self::whitespaceSensitive($snippet), $snippet);
        }
    }

    /**
     * No listed template puts literal text where whitespace is kept or where its exact text is data: nothing of
     * WHITESPACE_SENSITIVE, no class of a white-space: pre* rule (but the allowed ones).
     */
    public function testNoListedTemplateKeepsWhitespace(): void
    {
        $templates = $this->listedTemplates();
        $preClasses = $this->classesOfWhitespacePreservingRules($templates);

        foreach (['mpe-badge', 'msp-confirm-message'] as $known) {
            self::assertContains($known, $preClasses, 'The stylesheet scan finds the known white-space: pre rules');
        }

        $found = [];

        foreach ($templates as $path => $code) {
            foreach (self::whitespaceSensitive($code) as $reason) {
                $found[] = $path . ': ' . $reason;
            }

            $code = (string) preg_replace('~\{#.*?#\}~s', '', $code);

            foreach ($preClasses as $class) {
                if (!array_key_exists($class, self::PRE_ALLOWED) && preg_match('~class="[^"]*\b' . preg_quote($class, '~') . '\b~', $code) === 1) {
                    $found[] = $path . ' .' . $class;
                }
            }
        }

        self::assertSame([], $found, 'Whitespace-sensitive text in a template ChromeWhitespace collapses');
    }

    /**
     * @return iterable<string, array{string, list<string>}>
     */
    public static function stylesheets(): iterable
    {
        yield 'a plain rule' => ['.a { color: red; white-space: pre; }', ['a']];
        yield 'nested & suffix' => [".a {\n  color: red;\n  &-b {\n    white-space: pre-line\n  }\n}", ['a-b']];
        yield 'nested under a selector list' => ['.a, .c { &-b { white-space: pre-wrap; } }', ['a-b', 'c-b']];
        yield 'nested descendant' => ['.a { .b > .c:not(.d .e) { white-space: pre } }', ['c']];
        yield 'parent after the child' => ['.a { .b & { white-space: break-spaces } }', ['a']];
        yield 'pseudo-class on the parent' => ['.a { &:hover { white-space: pre } }', ['a']];
        yield 'inside @media' => ['.a { @media (min-width: 1px) { white-space: pre; } }', ['a']];
        yield 'interpolation and a line comment' => [".a {\n  // .x { white-space: pre }\n  color: #{\$c};\n  .b { white-space: pre }\n}", ['b']];
        yield 'minified' => ['.a{white-space:pre}.b{white-space:nowrap}@media(min-width:1px){.c{white-space:pre-line}}', ['a', 'c']];
        yield 'nowrap and normal' => ['.a { white-space: nowrap; } .b { &-c { white-space: normal } }', []];
    }

    /**
     * @param list<string> $expected
     */
    #[DataProvider('stylesheets')]
    public function testTheStylesheetScanResolvesNesting(string $css, array $expected): void
    {
        self::assertSame($expected, self::whitespacePreservingClasses($css));
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function css(): iterable
    {
        yield 'line breaks between rules' => [".a{x:1}\n.b{y:2}\n", '.a{x:1}.b{y:2}'];
        yield 'the space after @media' => ['@media (min-width:768px){.a{x:1}}', '@media(min-width:768px){.a{x:1}}'];
        yield 'neighbouring rules of one media query' => ["@media (a){.x{p:1}}\n@media (a){.y{q:2}}", '@media(a){.x{p:1}.y{q:2}}'];
        yield 'neighbouring rules of one selector' => [":root{--a:1}\n:root{--b:2;}\n:root{--c:3}", ':root{--a:1;--b:2;--c:3}'];
        yield 'never across another rule' => ["@media (a){.x{p:1}}\n.y{q:2}\n@media (a){.z{r:3}}", '@media(a){.x{p:1}}.y{q:2}@media(a){.z{r:3}}'];
        yield 'other queries stay apart' => ["@media (a){.x{p:1}}\n@media (b){.x{p:2}}", '@media(a){.x{p:1}}@media(b){.x{p:2}}'];
        yield 'other at-rules never merge' => [
            "@font-face{font-family:A}\n@font-face{font-family:B}\n@supports not selector(:has(a)){.x{p:1}}\n@supports not selector(:has(a)){.y{p:1}}",
            '@font-face{font-family:A}@font-face{font-family:B}@supports not selector(:has(a)){.x{p:1}}@supports not selector(:has(a)){.y{p:1}}',
        ];
        yield 'strings and comments keep their braces' => ["@property --x{syntax:'<a{b>';inherits:true}\n.a{content:\"}\\\"{\"}\n/* { */.b{c:d}", "@property --x{syntax:'<a{b>';inherits:true}.a{content:\"}\\\"{\"}/* { */.b{c:d}"];
        yield 'a line break inside a rule reads as a space' => ["@media (a)\n  and (b){.x{p:1}}", '@media(a) and (b){.x{p:1}}'];
        yield 'nothing' => ['', ''];
    }

    #[DataProvider('css')]
    public function testCompactCss(string $css, string $expected): void
    {
        self::assertSame($expected, ChromeWhitespace::compactCss($css));
    }

    /**
     * The navigation's critical CSS is compacted, not collapsed: it renders as compactCss() of its source - the rules of
     * the source (NavLayoutCssTest reads its lines), in one text node: no Twig code in it but its comment. Chromium read
     * both to the same 590 declarations in the same order and context (weight diet 2026-10-14).
     */
    public function testTheCriticalCssRendersCompacted(): void
    {
        $source = (string) file_get_contents($this->templates() . ChromeWhitespace::CRITICAL_CSS);
        $css = trim((string) preg_replace('~\{#.*?#\}~s', '', $source));
        self::assertDoesNotMatchRegularExpression('~\{[{%]~', $css, 'Plain CSS: no Twig output or tag');

        $twig = new Environment(new ArrayLoader([ChromeWhitespace::CRITICAL_CSS => $source]));
        $twig->addExtension(new ChromeWhitespaceTwigExtension());
        $rendered = $twig->render(ChromeWhitespace::CRITICAL_CSS);

        self::assertSame(ChromeWhitespace::compactCss($css), trim($rendered));
        self::assertStringNotContainsString("\n", trim($rendered));
        self::assertLessThan(strlen($css), strlen($rendered));
    }

    /**
     * A guest's /en/faq (no avatar - _player_avatar and XpRing are shared with page content, not listed): no indentation
     * left in the bars, the Menu and its shell. Two text nodes may leave a space each (" " + " ").
     */
    public function testThePagesChromeHasNoIndentation(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/faq');
        $html = (string) $browser->getResponse()->getContent();

        foreach ([['<header class="site-header', '</header>'], ['<nav id="bottom-nav"', '</nav>'], ['<nav id="site-menu"', '</nav>'], ['<dialog id="menu-layer"', '</dialog>'], ['<footer class="site-footer', '</footer>']] as [$start, $end]) {
            $from = strpos($html, $start);
            self::assertIsInt($from, $start);
            $part = substr($html, $from, (int) strpos($html, $end, $from) - $from);

            self::assertDoesNotMatchRegularExpression('~\n[ \t]{3,}\S~', $part, $start);
        }
    }

    /**
     * @return list<string> the reasons of WHITESPACE_SENSITIVE that match the template's code
     */
    private static function whitespaceSensitive(string $code): array
    {
        $code = (string) preg_replace('~\{#.*?#\}~s', '', $code);
        $reasons = [];

        foreach (self::WHITESPACE_SENSITIVE as $pattern => $reason) {
            if (preg_match($pattern, $code) === 1) {
                $reasons[] = $reason;
            }
        }

        return $reasons;
    }

    /**
     * The classes of the elements white-space: pre* rules style: every SCSS file of assets/styles (nested rules
     * resolved) and the <style> of the listed templates (the critical CSS)
     *
     * @param array<string, string> $templates
     * @return list<string>
     */
    private function classesOfWhitespacePreservingRules(array $templates): array
    {
        $classes = [];
        $root = dirname(__DIR__, 2) . '/assets/styles';

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if ($file instanceof \SplFileInfo && $file->getExtension() === 'scss') {
                $classes = [...$classes, ...self::whitespacePreservingClasses((string) file_get_contents($file->getPathname()))];
            }
        }

        foreach ($templates as $name => $code) {
            $code = (string) preg_replace(['~\{#.*?#\}~s', '~\{\{.*?\}\}~s', '~\{%.*?%\}~s'], '', $code);
            $styles = str_ends_with($name, 'css.html.twig') ? [$code] : [];

            if (preg_match_all('~<style\b[^>]*>(.*?)</style>~is', $code, $blocks) > 0) {
                $styles = [...$styles, ...$blocks[1]];
            }

            foreach ($styles as $css) {
                $classes = [...$classes, ...self::whitespacePreservingClasses($css)];
            }
        }

        return array_values(array_unique($classes));
    }

    /**
     * The classes of the element (the last compound selector) of every rule declaring white-space: pre*, with SCSS
     * nesting resolved (&, descendants, @media and other at-rules inside a rule)
     *
     * @return list<string>
     */
    private static function whitespacePreservingClasses(string $css): array
    {
        $css = (string) preg_replace(['~/\*.*?\*/~s', '~(^|\s)//[^\n]*~', '~#\{[^}]*\}~'], ['', '$1', 'x'], $css);
        /** @var list<list<string>> $stack */
        $stack = [['']];
        $buffer = '';
        $classes = [];

        for ($i = 0, $length = strlen($css); $i < $length; $i++) {
            $char = $css[$i];

            if ($char === '{') {
                $stack[] = self::nest($stack[count($stack) - 1], trim($buffer));
                $buffer = '';

                continue;
            }

            if ($char !== '}' && $char !== ';') {
                $buffer .= $char;

                continue;
            }

            if (preg_match('~(^|\s)white-space\s*:\s*(pre|break-spaces)~', $buffer) === 1) {
                foreach ($stack[count($stack) - 1] as $selector) {
                    $classes = [...$classes, ...self::subjectClasses($selector)];
                }
            }

            if ($char === '}' && count($stack) > 1) {
                array_pop($stack);
            }

            $buffer = '';
        }

        return array_values(array_unique($classes));
    }

    /**
     * @param list<string> $parents
     * @return list<string>
     */
    private static function nest(array $parents, string $selector): array
    {
        // @media, @supports, @include … { }: the declarations inside still style the rule they sit in
        if (str_starts_with($selector, '@')) {
            return $parents;
        }

        $resolved = [];

        foreach (preg_split('~,(?![^(]*\))~', $selector) ?: [] as $part) {
            foreach ($parents as $parent) {
                $resolved[] = str_contains($part, '&') ? str_replace('&', $parent, trim($part)) : trim($parent . ' ' . trim($part));
            }
        }

        return $resolved;
    }

    /**
     * @return list<string>
     */
    private static function subjectClasses(string $selector): array
    {
        $compounds = preg_split('#\s*[>+~]\s*|\s+#', trim((string) preg_replace('~\([^)]*\)~', '', $selector))) ?: [];
        preg_match_all('~\.([a-zA-Z][\w-]*)~', (string) end($compounds), $names);

        return $names[1];
    }

    /**
     * @return array<string, string>
     */
    private function listedTemplates(): array
    {
        $templates = [];

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($this->templates(), \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'twig') {
                continue;
            }

            $name = substr($file->getPathname(), strlen($this->templates()));

            if (ChromeWhitespace::appliesTo($name)) {
                $templates[$name] = (string) file_get_contents($file->getPathname());
            }
        }

        self::assertGreaterThan(20, count($templates));

        return $templates;
    }

    private function templates(): string
    {
        return dirname(__DIR__, 2) . '/templates/';
    }
}
