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
     * No listed template puts literal text where whitespace is kept: no <pre>, no <textarea>, no inline white-space
     * style, no class of a white-space: pre* rule (but the allowed ones); no line continuation in an inline script.
     */
    public function testNoListedTemplateKeepsWhitespace(): void
    {
        $preClasses = $this->classesOfWhitespacePreservingRules();
        self::assertNotSame([], $preClasses, 'The stylesheet scan finds the known white-space: pre rules');
        $found = [];

        foreach ($this->listedTemplates() as $path => $code) {
            $code = (string) preg_replace('~\{#.*?#\}~s', '', $code);

            foreach (['~<pre\b~i', '~<textarea\b~i', '~style="[^"]*white-space~i', '~\\\\\n~'] as $pattern) {
                if (preg_match($pattern, $code) === 1) {
                    $found[] = $path . ' ' . $pattern;
                }
            }

            foreach ($preClasses as $class) {
                if (!array_key_exists($class, self::PRE_ALLOWED) && preg_match('~class="[^"]*\b' . preg_quote($class, '~') . '\b~', $code) === 1) {
                    $found[] = $path . ' .' . $class;
                }
            }
        }

        self::assertSame([], $found, 'Whitespace-preserving markup in a template ChromeWhitespace collapses');
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
     * @return list<string>
     */
    private function classesOfWhitespacePreservingRules(): array
    {
        $classes = [];
        $root = dirname(__DIR__, 2) . '/assets/styles';

        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS)) as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'scss') {
                continue;
            }

            $lines = file($file->getPathname()) ?: [];

            foreach ($lines as $number => $line) {
                if (preg_match('~white-space\s*:\s*(pre|break-spaces)~', $line) !== 1) {
                    continue;
                }

                // The selector of the block the declaration sits in: the nearest line above ending with {
                for ($i = $number - 1; $i >= 0; $i--) {
                    if (preg_match('~^(.*)\{\s*$~', $lines[$i], $selector) === 1) {
                        preg_match_all('~\.([a-zA-Z][\w-]*)~', $selector[1], $names);
                        $classes = [...$classes, ...$names[1]];

                        break;
                    }
                }
            }
        }

        return array_values(array_unique($classes));
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
