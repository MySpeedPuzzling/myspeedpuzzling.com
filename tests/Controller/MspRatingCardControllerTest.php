<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\TestClock;
use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Clock\MockClock;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The MSP Rating card (docs/features/msp-rating-history.md): the puzzler card's frame on the ladder, a noindex page of
 * its own, the profile's lazy block - and only for a player on the ladder, a global ranking (private players never,
 * the allow list included).
 */
final class MspRatingCardControllerTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string FRAME = 'player-card';
    private const string PROFILE_FRAME = 'msp-rating-history';

    public function testAFrameRequestAnswersTheCardWithItsStory(): void
    {
        $browser = $this->browser();
        $this->rateWithHistory(PlayerFixture::PLAYER_REGULAR);
        $this->rateWithHistory(PlayerFixture::PLAYER_ADMIN, 0.95);

        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#player-card .players-card.msp-card'));
        self::assertCount(1, $crawler->filter('#players-card-name'));
        // The name leads to the profile, out of the card's frame
        self::assertSame('/en/player-profile/' . PlayerFixture::PLAYER_REGULAR, $crawler->filter('#players-card-name a')->attr('href'));
        self::assertSame('_top', $crawler->filter('#players-card-name a')->attr('data-turbo-frame'));
        self::assertCount(1, $crawler->filter('[data-players-card-close]'));
        self::assertStringContainsString('Turbo-Frame', (string) $browser->getResponse()->headers->get('Vary'));
        // #2 behind Admin today, #1 a week ago (14 Jun)
        self::assertStringContainsString('#2', $crawler->filter('.msp-story-stat')->eq(0)->text());
        self::assertCount(1, $crawler->filter('.msp-story-stat .lb-move.lb-move-down'));
        // 873 today, 900 a week ago
        self::assertSame('(−27)', trim($crawler->filter('.msp-story-stat .lb-move-points')->text()));
        // Best place #1, the two charts
        self::assertStringContainsString('#1', $crawler->filter('.msp-story-stat')->eq(2)->text());
        self::assertCount(2, $crawler->filter('.msp-story-canvas canvas'));

        foreach ($crawler->filter('a[href^="/en/player"]') as $link) {
            self::assertInstanceOf(\DOMElement::class, $link);
            self::assertSame('_top', $link->getAttribute('data-turbo-frame'));
        }
    }

    public function testADirectVisitIsANoindexPageWithTheProfileAsCanonical(): void
    {
        $browser = $this->browser();
        $this->rateWithHistory(PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/msp-rating/card/' . PlayerFixture::PLAYER_REGULAR);

        self::assertResponseIsSuccessful();
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertStringEndsWith('/en/player-profile/' . PlayerFixture::PLAYER_REGULAR, (string) $crawler->filter('link[rel="canonical"]')->attr('href'));
    }

    public function testTheProfileBlockLoadsIntoItsOwnFrame(): void
    {
        $browser = $this->browser();
        $this->rateWithHistory(PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/msp-rating/card/' . PlayerFixture::PLAYER_REGULAR, server: ['HTTP_TURBO_FRAME' => self::PROFILE_FRAME]);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('turbo-frame#msp-rating-history .msp-profile-history'));
        self::assertCount(0, $crawler->filter('.players-card'));
    }

    public function testAPlayerOffTheLadderHasNoCard(): void
    {
        $browser = $this->browser();

        // Not rated
        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR);
        self::assertResponseStatusCodeSame(404);
        self::assertCount(1, $crawler->filter('turbo-frame#player-card .players-card-unavailable'));

        // Opted out of rankings
        $this->rateWithHistory(PlayerFixture::PLAYER_REGULAR);
        $this->database()->executeStatement('UPDATE player SET ranking_opted_out = true WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]);
        $this->card($browser, PlayerFixture::PLAYER_REGULAR);
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/en/msp-rating/card/' . PlayerFixture::PLAYER_REGULAR);
        self::assertResponseStatusCodeSame(404);

        // The profile's frame answers an empty frame
        $crawler = $browser->request('GET', '/en/msp-rating/card/' . PlayerFixture::PLAYER_REGULAR, server: ['HTTP_TURBO_FRAME' => self::PROFILE_FRAME]);
        self::assertResponseStatusCodeSame(404);
        self::assertCount(1, $crawler->filter('turbo-frame#msp-rating-history'));
    }

    public function testAPrivatePlayerHasNoCardEvenForTheAllowedFriend(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_WITH_FAVORITES);
        $this->rateWithHistory(PlayerFixture::PLAYER_PRIVATE);

        $this->card($browser, PlayerFixture::PLAYER_PRIVATE);
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString('Jane Smith', (string) $browser->getResponse()->getContent());
    }

    public function testAPlayerTheViewerBlockedHasNoCard(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        $this->rateWithHistory(PlayerFixture::PLAYER_WITH_STRIPE);
        $this->database()->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, " . TestClock::SQL_NOW . ", 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_WITH_FAVORITES, 'blocked' => PlayerFixture::PLAYER_WITH_STRIPE],
        );

        $this->card($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertResponseIsSuccessful();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $this->card($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertResponseStatusCodeSame(404);
        self::assertStringNotContainsString(PlayerFixture::PLAYER_WITH_STRIPE_NAME, (string) $browser->getResponse()->getContent());
    }

    public function testTheCardCostsThreeStatementsForAGuest(): void
    {
        $browser = $this->browser();
        $this->rateWithHistory(PlayerFixture::PLAYER_REGULAR);

        $this->startCountingQueries($browser);
        $this->card($browser, PlayerFixture::PLAYER_REGULAR);

        self::assertResponseIsSuccessful();
        // The profile (place + the compared day's place), the rating + ladder size, the stored days
        $this->assertQueryCountAtMost($browser, 3, 'MSP Rating card for a guest');
    }

    private function browser(null|string $viewerId = null): KernelBrowser
    {
        $browser = self::createClient();
        self::getContainer()->set(ClockInterface::class, new MockClock(new DateTimeImmutable('2026-06-21 12:00', new DateTimeZone('UTC'))));

        if ($viewerId !== null) {
            TestingLogin::asPlayer($browser, $viewerId);
        }

        return $browser;
    }

    private function card(KernelBrowser $browser, string $playerId): Crawler
    {
        return $browser->request('GET', '/en/msp-rating/card/' . $playerId, server: ['HTTP_TURBO_FRAME' => self::FRAME]);
    }

    private function database(): Connection
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }

    /**
     * Rated today ($rating), and on the stored days 10-20 Jun at 0.9 - the only one rated then is place 1
     */
    private function rateWithHistory(string $playerId, float $rating = 0.8734): void
    {
        $database = $this->database();
        $database->executeStatement(
            'INSERT INTO player_elo (id, player_id, pieces_count, elo_rating, computed_at) VALUES (:id, :player, 500, :rating, ' . TestClock::SQL_NOW . ')',
            ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'rating' => $rating],
        );

        if ($playerId === PlayerFixture::PLAYER_ADMIN) {
            return;
        }

        for ($day = 10; $day <= 20; $day++) {
            $database->executeStatement(
                'INSERT INTO player_rating_snapshot (id, player_id, pieces_count, snapshot_date, elo_rating, elo_rank, computed_at) VALUES (gen_random_uuid(), :player, 500, :day, 0.9, 1, ' . TestClock::SQL_NOW . ')',
                ['player' => $playerId, 'day' => sprintf('2026-06-%02d', $day)],
            );
        }
    }
}
