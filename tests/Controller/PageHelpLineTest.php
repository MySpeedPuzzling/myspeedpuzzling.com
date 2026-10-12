<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionRoundFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Routing\RouterInterface;

/**
 * The end-of-page line (docs/features/navigation README §4.5, implementation plan HELP-04): "Something wrong on this
 * page? Tell us" at the end of <main> on every base-layout page - guests included, it depends on the URL only - and its
 * link opens the Help sheet with the page attached (help_opener_attrs(): modal-frame, own scrim, nofollow). Not on the
 * sign-in / sign-up pages, the bar-free pages (they empty nav_chrome) and the help page itself.
 */
final class PageHelpLineTest extends WebTestCase
{
    /**
     * @return iterable<string, array{null|string, string, string}> viewer, page, place
     */
    public static function pagesWithTheLine(): iterable
    {
        yield 'faq, guest' => [null, '/en/faq', 'none'];
        yield 'a puzzle page, guest' => [null, '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01, 'puzzles'];
        yield 'hub, player' => [PlayerFixture::PLAYER_REGULAR, '/en/hub', 'home'];
        yield 'a puzzle page with a query, player' => [PlayerFixture::PLAYER_REGULAR, '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01 . '?tab=times', 'puzzles'];
    }

    #[DataProvider('pagesWithTheLine')]
    public function testThePageEndsWithTheLine(null|string $playerId, string $page, string $place): void
    {
        $browser = self::createClient();

        if ($playerId !== null) {
            TestingLogin::asPlayer($browser, $playerId);
        }

        $crawler = $browser->request('GET', $page);
        self::assertResponseIsSuccessful();

        $line = $crawler->filter('main > .page-help-line');
        self::assertCount(1, $line);
        // The end of the main content
        self::assertCount(1, $crawler->filter('main > .page-help-line:last-child'));
        self::assertSame('Something wrong on this page? Tell us', trim((string) preg_replace('/\s+/', ' ', $line->text())));

        $link = $line->filter('a');
        self::assertCount(1, $link);
        self::assertSame('Tell us', trim($link->text()));
        self::assertSame(self::getContainer()->get(RouterInterface::class)->generate('help', ['url' => $page, '_locale' => 'en']), $link->attr('href'));
        self::assertSame('modal-frame', $link->attr('data-turbo-frame'));
        self::assertSame('own', $link->attr('data-modal-scrim'));
        self::assertContains('nofollow', explode(' ', (string) $link->attr('rel')));
        self::assertSame('page/' . $place . '/help_line', $link->attr('data-nav'));

        if ($playerId === null) {
            // Guest HTML stays shared-cacheable (the link depends on the URL only)
            self::assertTrue($browser->getResponse()->headers->hasCacheControlDirective('public'), (string) $browser->getResponse()->headers->get('Cache-Control'));
        }
    }

    public function testEveryLocaleSaysIt(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/de/haeufige-fragen');

        self::assertResponseIsSuccessful();
        self::assertSame('Stimmt auf dieser Seite etwas nicht? Sagen Sie es uns', trim((string) preg_replace('/\s+/', ' ', $crawler->filter('main > .page-help-line')->text())));
        self::assertSame('/de/hilfe?url=/de/haeufige-fragen', $crawler->filter('main > .page-help-line a')->attr('href'));
    }

    /**
     * @return iterable<string, array{null|string, string}> viewer, page
     */
    public static function pagesWithoutTheLine(): iterable
    {
        // Bar-free pages (they empty header, footer and nav_chrome)
        yield 'participants sheet' => [PlayerFixture::PLAYER_REGULAR, '/en/participants-sheet/' . CompetitionFixture::COMPETITION_UNAPPROVED];
        yield 'round stopwatch' => [null, '/en/round-stopwatch/' . CompetitionRoundFixture::ROUND_WJPC_QUALIFICATION];
        // The sign-in / sign-up pages (_auth_layout.html.twig)
        yield 'login' => [null, '/login'];
        yield 'register' => [null, '/register'];
        // The help page is Help already
        yield 'help page' => [PlayerFixture::PLAYER_REGULAR, '/en/help?url=' . rawurlencode('/en/faq')];
    }

    #[DataProvider('pagesWithoutTheLine')]
    public function testNotOnThesePages(null|string $playerId, string $page): void
    {
        $browser = self::createClient();

        if ($playerId !== null) {
            TestingLogin::asPlayer($browser, $playerId);
        }

        $crawler = $browser->request('GET', $page);
        self::assertResponseIsSuccessful();

        self::assertCount(0, $crawler->filter('.page-help-line'));
        self::assertStringNotContainsString('Something wrong on this page?', (string) $browser->getResponse()->getContent());
    }

    /**
     * Every page that empties the bars empties the line too (the bar-free set is pinned by NavLayoutCssTest), and so
     * does the auth layout
     */
    public function testEveryBarFreeTemplateEmptiesTheLine(): void
    {
        $root = dirname(__DIR__, 2);
        $checked = 0;

        foreach ((new Finder())->files()->in($root . '/templates')->name('*.twig') as $file) {
            $code = $file->getContents();

            if (preg_match('~\{%\s*extends\s+\'base\.html\.twig\'~', $code) !== 1 || preg_match('~\{%\s*block header\s*%\}\s*\{%\s*endblock\s*%\}~', $code) !== 1) {
                continue;
            }

            self::assertMatchesRegularExpression('~\{%\s*block page_help_line\s*%\}\s*\{%\s*endblock\s*%\}~', $code, $file->getRelativePathname() . ' empties the bars but not the end-of-page line');
            $checked++;
        }

        self::assertGreaterThanOrEqual(3, $checked);
        self::assertMatchesRegularExpression('~\{%\s*block page_help_line\s*%\}\s*\{%\s*endblock\s*%\}~', (string) file_get_contents($root . '/templates/_auth_layout.html.twig'));
    }
}
