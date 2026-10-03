<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component\Players;

use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecalculateCommunityStats;
use SpeedPuzzling\Web\MessageHandler\RecalculateCommunityStatsHandler;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * Instant search on the Players page (docs/features/players-page/README.md, stream S1). Fixtures: PLAYER_PRIVATE
 * ("Jane Smith", code player2) allows PLAYER_WITH_FAVORITES only.
 */
final class SearchTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use QueryCountAssertions;

    public function testResultsAppearWhileTypingAsPersonRowsThatOpenTheCard(): void
    {
        $browser = $this->browser();
        $component = $this->createLiveComponent('Players:Search', [], $browser);
        $component->setRouteLocale('en');

        self::assertCount(0, $this->crawl($component->render()->toString())->filter('.players-search-result'), 'Nothing before typing');

        $crawler = $this->crawl($component->set('query', 'Sarah')->render()->toString());

        $row = $crawler->filter('.players-search-result a.players-person');
        self::assertCount(1, $row);
        self::assertSame('/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE, $row->attr('href'));
        self::assertSame('players-card#open', $row->attr('data-action'));
        self::assertSame('/en/puzzler-card/' . PlayerFixture::PLAYER_WITH_STRIPE, $row->attr('data-players-card-url-param'));
        self::assertSame('Sarah', $row->filter('.players-person-name .search-highlight')->text());
        self::assertSame('#PLAYER4', $row->filter('.players-person-chip')->text());
        self::assertMatchesRegularExpression('/^\d+ puzzles?$/', trim($row->filter('.players-person-value')->text()));
        self::assertSame('1 puzzler found', trim($crawler->filter('.players-search-status')->text()));
    }

    public function testACodeIsFoundWithOrWithoutTheHash(): void
    {
        $browser = $this->browser();

        foreach (['#player4', 'PLAYER4'] as $query) {
            $crawler = $this->search($browser, $query);
            self::assertSame(
                '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE,
                $crawler->filter('.players-search-result a.players-person')->first()->attr('href'),
                $query,
            );
        }
    }

    public function testTooShortAndNoMatchSayWhatToTry(): void
    {
        $browser = $this->browser();

        $crawler = $this->search($browser, 'S');
        self::assertCount(0, $crawler->filter('.players-search-result'));
        self::assertSame('Type at least two letters of the name, or the #code.', trim($crawler->filter('.players-search-message')->text()));

        $crawler = $this->search($browser, 'Zyxwvut');
        self::assertCount(0, $crawler->filter('.players-search-result'));
        self::assertSame('No puzzler matches “Zyxwvut”. Try a part of the name or the #code.', trim($crawler->filter('.players-search-message')->text()));
    }

    public function testAPrivatePlayerIsFoundByTheExactCodeOnlyAndMasked(): void
    {
        $browser = $this->browser();

        $crawler = $this->search($browser, 'Jane');
        self::assertStringNotContainsString(PlayerFixture::PLAYER_PRIVATE, $crawler->html());

        $crawler = $this->search($browser, 'player2');
        $hidden = $crawler->filter('.players-search-hidden');
        self::assertCount(1, $hidden);
        self::assertStringContainsString('Hidden Puzzler', $hidden->text());
        self::assertStringContainsString('#PLAYER2', $hidden->text());
        self::assertStringNotContainsString('Jane Smith', $crawler->html());
        self::assertCount(0, $crawler->filter('[data-players-card-url-param="/en/puzzler-card/' . PlayerFixture::PLAYER_PRIVATE . '"]'), 'No card for a masked player');
        self::assertCount(0, $crawler->filter('.players-person-value'), 'No numbers for a masked player');

        // On her allow list: found by name like anybody else
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $crawler = $this->search($browser, 'Jane');
        self::assertSame('/en/puzzler-card/' . PlayerFixture::PLAYER_PRIVATE, $crawler->filter('.players-search-result a.players-person')->attr('data-players-card-url-param'));
    }

    public function testTheBlockerNeverFindsThePlayerTheyBlocked(): void
    {
        $browser = $this->browser();
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_WITH_FAVORITES, 'blocked' => PlayerFixture::PLAYER_WITH_STRIPE],
        );

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        self::assertCount(1, $this->search($browser, 'Sarah')->filter('.players-search-result'));

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $crawler = $this->search($browser, 'Sarah');
        self::assertCount(0, $crawler->filter('.players-search-result'));
        self::assertStringNotContainsString(PlayerFixture::PLAYER_WITH_STRIPE, $crawler->html());
    }

    public function testAtMostTenResultsAndASayWhenThereAreMore(): void
    {
        $browser = $this->browser();
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO player (id, code, name, registered_at)
             SELECT gen_random_uuid(), 'quokka' || n, 'Quokka Puzzler ' || n, NOW() FROM generate_series(1, :count) AS n",
            ['count' => 12],
            ['count' => ParameterType::INTEGER],
        );

        $crawler = $this->search($browser, 'Quokka');

        self::assertCount(10, $crawler->filter('.players-search-result'));
        self::assertCount(1, $crawler->filter('.players-search-more'));
    }

    public function testAResultCostsTwoStatements(): void
    {
        $browser = $this->browser();
        $component = $this->createLiveComponent('Players:Search', ['query' => 'Sarah'], $browser);
        $component->setRouteLocale('en');
        $component->render();

        $this->startCountingQueries($browser);
        $component->refresh();

        // The search + the puzzle counts of the rows
        $this->assertQueryCountAtMost($browser, 2, 'Instant search for a guest');
    }

    private function browser(): KernelBrowser
    {
        $browser = self::createClient();
        (self::getContainer()->get(RecalculateCommunityStatsHandler::class))(new RecalculateCommunityStats());

        return $browser;
    }

    private function search(KernelBrowser $browser, string $query): Crawler
    {
        $component = $this->createLiveComponent('Players:Search', ['query' => $query], $browser);
        $component->setRouteLocale('en');

        return $this->crawl($component->refresh()->render()->toString());
    }

    private function crawl(string $html): Crawler
    {
        return new Crawler($html, 'http://localhost');
    }
}
