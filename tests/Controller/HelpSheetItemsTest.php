<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestDouble\FixedXpLaunchReadiness;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Routing\RouterInterface;

/**
 * The Help sheet's order and context (docs/features/navigation README §4.5, implementation plan HELP-02): feedback box ·
 * "Suggest a correction to this puzzle" (puzzle pages only, first; it opens inside the sheet) · Ideas & feature requests
 * · FAQ · Getting started · How progress works (XP visible) · Where did it go? (signed in) · Language (the page the sheet
 * was opened on, in the other five languages) · Reload page (installed app only: standalone-only). No "What's new" or
 * "Thanks & resources" until their pages exist.
 */
final class HelpSheetItemsTest extends WebTestCase
{
    private const array FRAME = ['HTTP_TURBO_FRAME' => 'modal-frame'];
    private const array LOCALES = ['de', 'cs', 'fr', 'es', 'ja'];

    public function testOnAPuzzlePageTheCorrectionComesFirst(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR);
        $page = '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01 . '?tab=times';

        $sheet = $this->sheet($browser, $page);

        self::assertSame(
            ['feedback', 'suggest_correction', 'feature_requests', 'faq', 'getting_started', 'progression', 'where_did_it_go', 'language', 'reload'],
            $this->itemIds($sheet),
        );

        // The puzzle's own "Suggest a change", inside the sheet: the same layer and history entry
        $correction = $sheet->filter('[data-help-item="suggest_correction"] a');
        self::assertCount(1, $correction);
        self::assertSame('/en/puzzle/' . PuzzleFixture::PUZZLE_500_01 . '/suggest-change', $correction->attr('href'));
        self::assertSame('modal-frame', $correction->attr('data-turbo-frame'));
        self::assertSame('help/help/suggest_correction', $correction->attr('data-nav'));
        self::assertStringContainsString('Suggest a correction to this puzzle', $correction->text());
        self::assertStringContainsString('Wrong name, brand, piece count, code or photo? Or a duplicate?', $correction->text());

        // The page the sheet is about, in the other five languages - its query kept
        $router = self::getContainer()->get(RouterInterface::class);
        $expected = [];

        foreach (self::LOCALES as $locale) {
            $expected[$locale] = $router->generate('puzzle_detail', ['puzzleId' => PuzzleFixture::PUZZLE_500_01, 'tab' => 'times', '_locale' => $locale]);
        }

        self::assertSame($expected, $this->languageLinks($sheet));

        // Reload page: the installed app only, the page itself
        $reload = $sheet->filter('[data-help-item="reload"]');
        self::assertContains('standalone-only', explode(' ', (string) $reload->attr('class')));
        $link = $reload->filter('a');
        self::assertSame($page, $link->attr('href'));
        self::assertSame('false', $link->attr('data-turbo'));
        self::assertSame('help-sheet#reload', $link->attr('data-action'));
        self::assertSame('help/help/reload', $link->attr('data-nav'));
        self::assertSame('Reload page', trim($link->text()));
    }

    public function testOnAnotherPageThereIsNoCorrection(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR);

        $sheet = $this->sheet($browser, '/en/faq');

        self::assertSame(
            ['feedback', 'feature_requests', 'faq', 'getting_started', 'progression', 'where_did_it_go', 'language', 'reload'],
            $this->itemIds($sheet),
        );
        self::assertCount(0, $sheet->filter('a[href*="/suggest-change"]'));
        $router = self::getContainer()->get(RouterInterface::class);
        $faqs = [];

        foreach (self::LOCALES as $locale) {
            $faqs[$locale] = $router->generate('faq', ['_locale' => $locale]);
        }

        self::assertSame($faqs, $this->languageLinks($sheet));
        self::assertContains('standalone-only', explode(' ', (string) $sheet->filter('[data-help-item="reload"]')->attr('class')));
        self::assertSame('/en/faq', $sheet->filter('[data-help-item="reload"] a')->attr('href'));

        // Nothing the README keeps out until its page exists
        foreach (['whats_new', 'thanks', 'thanks_resources'] as $id) {
            self::assertCount(0, $sheet->filter(sprintf('[data-help-item="%s"]', $id)));
        }

        self::assertStringNotContainsString('What\'s new', $sheet->text());
        self::assertStringNotContainsString('Thanks & resources', $sheet->text());
    }

    /**
     * Without a page (the bare help page in the sheet) the Language row leads to each language's homepage
     */
    public function testWithoutAPageTheLanguagesAreTheHomepages(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/help', server: self::FRAME);
        self::assertResponseIsSuccessful();

        $router = self::getContainer()->get(RouterInterface::class);
        $expected = [];

        foreach (self::LOCALES as $locale) {
            $expected[$locale] = $router->generate('homepage', ['_locale' => $locale]);
        }

        self::assertSame($expected, $this->languageLinks($crawler->filter('[data-help-sheet]')));
    }

    /**
     * The full page (no JS, a new tab, the app): its Language row is the help page itself in the other languages, the
     * same page attached; the correction is a plain visit there
     */
    public function testTheFullPage(): void
    {
        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR);
        $puzzle = '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01;

        $crawler = $browser->request('GET', '/en/help?url=' . rawurlencode($puzzle));
        self::assertResponseIsSuccessful();
        $sheet = $crawler->filter('main [data-help-sheet]');

        self::assertSame(
            ['feedback', 'suggest_correction', 'feature_requests', 'faq', 'getting_started', 'progression', 'where_did_it_go', 'language', 'reload'],
            $this->itemIds($sheet),
        );
        self::assertSame('_top', $sheet->filter('[data-help-item="suggest_correction"] a')->attr('data-turbo-frame'));

        $router = self::getContainer()->get(RouterInterface::class);
        $helpPages = [];

        foreach (self::LOCALES as $locale) {
            $helpPages[$locale] = $router->generate('help', ['url' => $puzzle, '_locale' => $locale]);
        }

        self::assertSame($helpPages, $this->languageLinks($sheet));

        // No sheet controller on the page: Reload is a plain full visit of the page
        $reload = $sheet->filter('[data-help-item="reload"] a');
        self::assertNull($reload->attr('data-action'));
        self::assertSame('false', $reload->attr('data-turbo'));
    }

    /**
     * "How progress works" follows the XP gate (XpFeatureGate): not before the launch, admins always
     */
    public function testHowProgressWorksFollowsTheXpGate(): void
    {
        FixedXpLaunchReadiness::$ready = false;

        $browser = $this->signedIn(PlayerFixture::PLAYER_REGULAR);
        self::assertNotContains('progression', $this->itemIds($this->sheet($browser, '/en/faq')));

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        self::assertContains('progression', $this->itemIds($this->sheet($browser, '/en/faq')));

        FixedXpLaunchReadiness::$ready = true;

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $sheet = $this->sheet($browser, '/en/faq');
        self::assertContains('progression', $this->itemIds($sheet));
        self::assertSame(
            self::getContainer()->get(RouterInterface::class)->generate('progression_hub', ['_locale' => 'en']),
            $sheet->filter('[data-help-item="progression"] a')->attr('href'),
        );
    }

    private function signedIn(string $playerId): KernelBrowser
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $playerId);

        return $browser;
    }

    private function sheet(KernelBrowser $browser, string $page): Crawler
    {
        $crawler = $browser->request('GET', '/en/help?url=' . rawurlencode($page), server: self::FRAME);
        self::assertResponseIsSuccessful();

        $sheet = $crawler->filter('turbo-frame#modal-frame > [data-help-sheet]');
        self::assertCount(1, $sheet);

        return $sheet;
    }

    /**
     * @return list<string>
     */
    private function itemIds(Crawler $sheet): array
    {
        return $sheet->filter('[data-help-item]')->each(static fn (Crawler $item): string => (string) $item->attr('data-help-item'));
    }

    /**
     * @return array<string, string> locale => href, in the row's order
     */
    private function languageLinks(Crawler $sheet): array
    {
        $links = [];

        foreach ($sheet->filter('[data-help-item="language"] a') as $link) {
            assert($link instanceof \DOMElement);
            // A whole-page visit remembered as the visitor's language, like every language switch
            self::assertSame('_top', $link->getAttribute('data-turbo-frame'));
            self::assertSame('click->locale-preference#save', $link->getAttribute('data-action'));
            self::assertSame($link->getAttribute('hreflang'), $link->getAttribute('data-locale-preference-locale-param'));
            self::assertSame('help/help/language_' . $link->getAttribute('hreflang'), $link->getAttribute('data-nav'));
            $links[$link->getAttribute('hreflang')] = $link->getAttribute('href');
        }

        return $links;
    }
}
