<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\AddComparisonSubject;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The comparison launcher (docs/features/player-comparison.md, D2): a pill on every page while a line-up holds somebody
 * other than the viewer - built from the viewer's own profile row, so it and the header's compare state cost no query.
 */
final class ComparisonLauncherTest extends WebTestCase
{
    use QueryCountAssertions;

    public function testThePillShowsTheNewestThreeAndHowManyAndOpensTheNewestLineUp(): void
    {
        // PLAYER_WITH_STRIPE: Solo = self + PLAYER_ADMIN + PLAYER_REGULAR, then the PLAYER_REGULAR & PLAYER_PRIVATE pair
        $crawler = $this->page(PlayerFixture::PLAYER_WITH_STRIPE, '/en/player-profile/' . PlayerFixture::PLAYER_ADMIN);

        $pill = $crawler->filter('a.comparison-launcher');
        self::assertCount(1, $pill);
        self::assertSame('/en/compare?kind=pairs', $pill->attr('href'));
        self::assertSame('Open comparison, 3 in your line-up', $pill->attr('aria-label'));
        self::assertSame('Compare', trim($pill->filter('.comparison-launcher-text')->text()));
        self::assertSame('3', trim($pill->filter('.comparison-launcher-count')->text()));
        self::assertStringNotContainsString('is-new', (string) $pill->attr('class'));

        // Newest first: the pair is a people icon, never faces; the players are avatars (never the viewer)
        $faces = $pill->filter('.comparison-launcher-face');
        self::assertCount(3, $faces);
        self::assertCount(1, $faces->eq(0)->filter('.bi-people-fill'));
        self::assertCount(1, $faces->eq(1)->filter('.lb-avatar'));
        self::assertCount(1, $faces->eq(2)->filter('.lb-avatar'));
        self::assertStringNotContainsString(PlayerFixture::PLAYER_PRIVATE, $pill->outerHtml());

        // Room for it under the footer's last links
        self::assertStringContainsString('has-comparison-launcher', (string) $crawler->filter('body')->attr('class'));
    }

    public function testNoPillWithoutSomebodyToCompareOrForGuests(): void
    {
        $browser = self::createClient();

        // PLAYER_ADMIN's line-ups are empty
        $crawler = $this->page(PlayerFixture::PLAYER_ADMIN, '/en/hub', $browser);
        self::assertCount(0, $crawler->filter('.comparison-launcher'));
        self::assertStringNotContainsString('has-comparison-launcher', (string) $crawler->filter('body')->attr('class'));

        $crawler = $this->page(null, '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE, $browser);
        self::assertCount(0, $crawler->filter('.comparison-launcher'));
    }

    public function testNoPillWhereAnotherBarSitsAtTheBottomOrAClockRuns(): void
    {
        $browser = self::createClient();

        foreach (['/en/multiscan', '/en/stopwatch'] as $url) {
            $crawler = $this->page(PlayerFixture::PLAYER_WITH_STRIPE, $url, $browser);
            self::assertCount(0, $crawler->filter('.comparison-launcher'), $url);
            self::assertStringNotContainsString('has-comparison-launcher', (string) $crawler->filter('body')->attr('class'), $url);
        }
    }

    public function testThePillPopsInOnlyRightAfterSomethingWasAdded(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('POST', '/en/compare/add', [
            '_token' => 'csrf-token',
            'subject' => 'p-' . PlayerFixture::PLAYER_WITH_STRIPE,
            'return' => '/en/hub',
        ], server: ['HTTP_ORIGIN' => 'http://localhost']);
        $crawler = $browser->followRedirect();

        $pill = $crawler->filter('a.comparison-launcher');
        self::assertCount(1, $pill);
        self::assertStringContainsString('is-new', (string) $pill->attr('class'));
        self::assertSame('/en/compare?kind=solo', $pill->attr('href'));
        self::assertSame('1', trim($pill->filter('.comparison-launcher-count')->text()));

        $crawler = $browser->request('GET', '/en/hub');
        self::assertStringNotContainsString('is-new', (string) $crawler->filter('a.comparison-launcher')->attr('class'));
    }

    public function testThePillAndTheHeaderStateCostNoQuery(): void
    {
        $browser = self::createClient();
        // PLAYER_WITH_FAVORITES starts with empty line-ups
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $pages = [
            '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE,
            '/en/hub',
            '/en/puzzle/' . PuzzleFixture::PUZZLE_500_01,
            '/en/teams/' . $this->fixturePairId($browser),
        ];

        $before = [];

        foreach ($pages as $url) {
            $before[$url] = $this->countedPage($browser, $url);
            self::assertCount(0, $browser->getCrawler()->filter('.comparison-launcher'), $url);
        }

        $bus = $browser->getContainer()->get(MessageBusInterface::class);
        $bus->dispatch(new AddComparisonSubject(PlayerFixture::PLAYER_WITH_FAVORITES, 'p-' . PlayerFixture::PLAYER_WITH_STRIPE));
        $bus->dispatch(new AddComparisonSubject(PlayerFixture::PLAYER_WITH_FAVORITES, 't-' . $this->fixturePairId($browser)));

        foreach ($pages as $url) {
            self::assertSame($before[$url], $this->countedPage($browser, $url), $url . ': the line-up rides on the viewer\'s profile row');
            self::assertCount(1, $browser->getCrawler()->filter('.comparison-launcher'), $url);

            $lineUpStatements = array_filter(
                $this->executedSql($browser),
                static fn (string $sql): bool => str_contains($sql, 'comparison_subject'),
            );
            self::assertCount(1, $lineUpStatements, $url . ': only the profile row reads the line-up');
        }

        // …and the pair's page knows it is in the line-up
        $crawler = $browser->getCrawler();
        self::assertCount(1, $crawler->filter('a.team-compare[href="/en/compare?kind=pairs"]'));
    }

    private function countedPage(KernelBrowser $browser, string $url): int
    {
        // Once to warm up, once counted
        $browser->request('GET', $url);
        $this->startCountingQueries($browser);
        $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $this->queryCount($browser);
    }

    private function page(null|string $viewer, string $url, null|KernelBrowser $browser = null): Crawler
    {
        $browser ??= self::createClient();

        if ($viewer !== null) {
            TestingLogin::asPlayer($browser, $viewer);
        } else {
            $browser->getCookieJar()->clear();
        }

        $crawler = $browser->request('GET', $url);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function fixturePairId(KernelBrowser $browser): string
    {
        $teamId = $browser->getContainer()->get(Connection::class)->fetchOne(
            'SELECT puzzling_team_id FROM puzzle_solving_time WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_12],
        );
        self::assertIsString($teamId);

        return $teamId;
    }
}
