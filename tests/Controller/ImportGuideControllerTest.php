<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\Generator\UrlGeneratorInterface;
use Symfony\Component\Yaml\Yaml;

/**
 * The public guide of the spreadsheet import (docs/features/data-import/entry-points.md).
 */
final class ImportGuideControllerTest extends WebTestCase
{
    use QueryCountAssertions;

    /**
     * Linked from other pages and from outside - renaming one breaks those links
     */
    private const array ANCHORS = [
        'step-file', 'step-check', 'step-people', 'step-puzzles', 'step-add', 'on-your-profile', 'take-back', 'questions',
        'what', 'your-file', 'never-twice', 'imported-result', 'unlisted', 'not-yet', 'start',
    ];

    /**
     * Every example is real HTML with the screens' own texts - its root is pinned (guided-flow.md §13)
     */
    private const array EXAMPLES = ['sample', 'file', 'check', 'people', 'puzzles', 'add', 'profile', 'undo'];

    private const array URLS = [
        'cs' => '/import-tabulky',
        'en' => '/en/import-your-spreadsheet',
        'es' => '/es/importar-hoja-de-calculo',
        'ja' => '/ja/スプレッドシートをインポート',
        'fr' => '/fr/importer-un-tableur',
        'de' => '/de/tabelle-importieren',
    ];

    #[DataProvider('provideLocales')]
    public function testGuideIsPublicAndIndexableInEveryLocale(string $locale): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', self::URLS[$locale]);

        self::assertResponseIsSuccessful();
        self::assertSame($locale, $crawler->filter('html')->attr('lang'));

        foreach (self::ANCHORS as $anchor) {
            self::assertCount(1, $crawler->filter('#' . $anchor), sprintf('Missing #%s on %s', $anchor, self::URLS[$locale]));
        }

        // An untranslated key would be printed as is
        $text = $crawler->filter('main')->text();
        self::assertStringNotContainsString('import_guide.', $text);
        self::assertStringNotContainsString('data_import.', $text);
        self::assertStringNotContainsString('%days%', $text);

        // The sample and the examples: there, labelled, and nothing in them can be used
        self::assertCount(6, $crawler->filter('[data-testid="guide-sample-row"]'));
        self::assertCount(6, $crawler->filter('[data-testid="guide-profile-row"]'));

        foreach (self::EXAMPLES as $example) {
            $figure = $crawler->filter('figure[data-testid="guide-example-' . $example . '"]');
            self::assertCount(1, $figure, $example);
            self::assertNotNull($figure->attr('inert'), $example);
            self::assertCount(1, $figure->filter('figcaption'), $example);
            self::assertCount(0, $figure->filter('form, input, button, a, [id], turbo-frame'), $example . ' must not be interactive');
        }

        // The questions: open without a script, and the same ones as structured data
        self::assertCount(11, $crawler->filter('#questions details[open]'));
        $faq = null;

        foreach ($crawler->filter('script[type="application/ld+json"]') as $script) {
            $data = json_decode($script->textContent, true, flags: JSON_THROW_ON_ERROR);

            if (is_array($data) && ($data['@type'] ?? null) === 'FAQPage') {
                $faq = $data;
            }
        }

        self::assertIsArray($faq);
        self::assertIsArray($faq['mainEntity']);
        self::assertCount(11, $faq['mainEntity']);

        // Indexable, its own canonical, and every language's address
        self::assertSame('index, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        $urlGenerator = self::getContainer()->get(UrlGeneratorInterface::class);
        self::assertSame(
            $urlGenerator->generate('import_guide', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL),
            $crawler->filter('link[rel="canonical"]')->attr('href'),
        );

        foreach (array_keys(self::URLS) as $other) {
            self::assertSame(
                $urlGenerator->generate('import_guide', ['_locale' => $other], UrlGeneratorInterface::ABSOLUTE_URL),
                $crawler->filter(sprintf('link[rel="alternate"][hreflang="%s"]', $other))->attr('href'),
            );
        }

        self::assertNotSame('', trim((string) $crawler->filter('meta[name="description"]')->attr('content')));
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function provideLocales(): iterable
    {
        foreach (array_keys(self::URLS) as $locale) {
            yield $locale => [$locale];
        }
    }

    public function testTheGuideSpeaksTheFlowsWords(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/import-your-spreadsheet');

        self::assertSame('How the import works', $crawler->filter('h1')->text());
        $text = $crawler->filter('main')->text();

        // The screens' own words (guided-flow.md §8) - and none of the engine's or the old ones
        foreach (['Looks like a match', 'Not in our database', 'Keep it just for me', "Don't import", 'Check and add', 'Undo', 'Add 5 to my profile', 'Looks right - continue', 'Select several', 'What this import added'] as $word) {
            self::assertStringContainsString($word, $text, $word);
        }

        self::assertDoesNotMatchRegularExpression('/\\b(pairing|paired|tiers?|candidates?|mappings?|layouts?|batch(es)?|pars(e|ed|ing)|twins?|errors?|invalid|take back|catalogue)\\b/i', $text);
        self::assertStringContainsString('A player code in your file becomes that player; names stay guests unless you say who they are.', $text);
        // "Unlisted" is explained where it is met, with the screens' exact text
        self::assertStringContainsString('A puzzle we do not have in our database yet. Only you see it, with its results and whether you own it - nothing is lost.', $crawler->filter('#unlisted')->text());
        self::assertStringContainsString('press Add again', $crawler->filter('#step-add')->text());
    }

    /**
     * "How this step works" on a screen of an import: the guide says the way back first - and only to an import.
     */
    public function testTheWayBackToTheImportIsOfferedOnlyForAnImportsAddress(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/import-your-spreadsheet?return=/en/import/0198a1b2-0000-7000-8000-000000000001/puzzles');
        self::assertResponseIsSuccessful();
        self::assertSame('/en/import/0198a1b2-0000-7000-8000-000000000001/puzzles', $crawler->filter('[data-testid="import-guide-back"]')->attr('href'));
        // An address with a query is not for the index
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));

        foreach (['//evil.example/en/import', 'https://evil.example/en/import', '/en/hub', '/en/import/../admin'] as $return) {
            $crawler = $browser->request('GET', '/en/import-your-spreadsheet?return=' . rawurlencode($return));
            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('[data-testid="import-guide-back"]'), $return);
        }
    }

    public function testEveryLocaleHasItsOwnAddress(): void
    {
        self::bootKernel();
        $urlGenerator = self::getContainer()->get(UrlGeneratorInterface::class);

        foreach (self::URLS as $locale => $url) {
            self::assertSame($url, rawurldecode($urlGenerator->generate('import_guide', ['_locale' => $locale])));
        }
    }

    public function testGuestStartButtonsCarryASafeReturnToTheImport(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/import-your-spreadsheet');

        $starts = $crawler->filter('[data-testid="import-guide-start"]');
        self::assertCount(2, $starts, 'At the top and at the end');

        $starts->each(static function (Crawler $start): void {
            $links = $start->filter('a')->extract(['href']);
            self::assertSame(['/register?return=/en/import', '/login?return=/en/import'], $links);

            foreach ($links as $link) {
                parse_str((string) parse_url($link, PHP_URL_QUERY), $query);
                self::assertIsString($query['return']);
                // The rule every consumer of ?return= applies (docs/features/return-url.md)
                self::assertNotNull(ReturnUrl::tryFrom($query['return']));
            }
        });
    }

    public function testPlayerStartsTheImportStraightAway(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/import-your-spreadsheet');

        self::assertResponseIsSuccessful();
        $crawler->filter('[data-testid="import-guide-start"]')->each(static function (Crawler $start): void {
            self::assertSame(['/en/import'], $start->filter('a')->extract(['href']));
        });
    }

    /**
     * The page is text: it costs a guest what the platform guide costs (the layout's statements), nothing of its own.
     */
    public function testGuideReadsNothingOfItsOwn(): void
    {
        $browser = self::createClient();

        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/getting-started');
        self::assertResponseIsSuccessful();
        $platformGuide = $this->queryCount($browser);

        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/import-your-spreadsheet');
        self::assertResponseIsSuccessful();

        self::assertSame($platformGuide, $this->queryCount($browser));
    }

    public function testGuideIsInTheSitemapInEveryLocale(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/sitemap-static.xml');

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        $urlGenerator = self::getContainer()->get(UrlGeneratorInterface::class);

        foreach (array_keys(self::URLS) as $locale) {
            self::assertStringContainsString(
                sprintf('<loc>%s</loc>', $urlGenerator->generate('import_guide', ['_locale' => $locale], UrlGeneratorInterface::ABSOLUTE_URL)),
                $content,
                $locale,
            );
        }
    }

    /**
     * Real copy in every language: each key of the guide and of the entry points exists in each catalogue (no
     * English fallback), is not empty, keeps its placeholders - and is not the English sentence left in place.
     */
    public function testEveryTextExistsInEveryLocale(): void
    {
        $english = $this->texts('en');
        self::assertGreaterThan(100, count($english));

        foreach (['cs', 'de', 'es', 'fr', 'ja'] as $locale) {
            $texts = $this->texts($locale);
            self::assertSame(array_keys($english), array_keys($texts), $locale);

            foreach ($texts as $key => $text) {
                self::assertNotSame('', trim($text), $locale . ' ' . $key);
                preg_match_all('/%[a-z_]+%/', $english[$key], $expected);
                preg_match_all('/%[a-z_]+%/', $text, $found);
                self::assertSame($expected[0], $found[0], $locale . ' ' . $key);

                if (str_word_count($english[$key]) > 3) {
                    self::assertNotSame($english[$key], $text, sprintf('%s %s is still English', $locale, $key));
                }
            }
        }
    }

    /**
     * @return array<string, string>
     */
    private function texts(string $locale): array
    {
        $catalogue = Yaml::parseFile(__DIR__ . '/../../translations/messages.' . $locale . '.yml');
        self::assertIsArray($catalogue);
        self::assertIsArray($catalogue['import_guide']);
        self::assertIsArray($catalogue['faq']);

        $texts = [];
        $this->flatten($catalogue['import_guide'], 'import_guide.', $texts);

        foreach (['question_import_spreadsheet', 'answer_import_spreadsheet'] as $key) {
            self::assertIsString($catalogue['faq'][$key] ?? null, $locale . ' faq.' . $key);
            $texts['faq.' . $key] = $catalogue['faq'][$key];
        }

        return $texts;
    }

    /**
     * @param array<mixed> $node
     * @param array<string, string> $texts
     */
    private function flatten(array $node, string $prefix, array &$texts): void
    {
        foreach ($node as $key => $value) {
            if (is_array($value)) {
                $this->flatten($value, $prefix . $key . '.', $texts);

                continue;
            }

            self::assertIsString($value, $prefix . $key);
            $texts[$prefix . $key] = $value;
        }
    }
}
