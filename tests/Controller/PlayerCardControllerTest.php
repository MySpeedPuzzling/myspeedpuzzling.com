<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecalculateCommunityStats;
use SpeedPuzzling\Web\MessageHandler\RecalculateCommunityStatsHandler;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\SkillTier;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The player card (docs/features/players-page/README.md, stream S1). Fixtures: PLAYER_WITH_STRIPE and PLAYER_ADMIN
 * are members; PLAYER_PRIVATE allows PLAYER_WITH_FAVORITES only; PLAYER_ADMIN's comparison line-ups are empty.
 */
final class PlayerCardControllerTest extends WebTestCase
{
    use QueryCountAssertions;

    private const string FRAME = 'player-card';

    public function testAFrameRequestAnswersTheCardFrameOnly(): void
    {
        $browser = $this->browser();

        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR);

        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringStartsWith('<turbo-frame id="player-card">', trim($content));
        self::assertStringNotContainsString('<html', $content);
        self::assertStringContainsString('Turbo-Frame', (string) $browser->getResponse()->headers->get('Vary'));

        self::assertSame('John Doe', trim($crawler->filter('#players-card-name')->text()));
        self::assertStringContainsString('#PLAYER1', $crawler->filter('.players-card-meta')->text());
        self::assertStringContainsString('Czechia', $crawler->filter('.players-card-meta')->text());
        self::assertCount(6, $crawler->filter('.players-card-stats dd'));
        self::assertStringContainsString('Competes in events', $crawler->filter('.players-card-chips')->text());

        // Leaving the card leaves the frame
        $profile = $crawler->filter('a.players-card-profile');
        self::assertSame('/en/player-profile/' . PlayerFixture::PLAYER_REGULAR, $profile->attr('href'));
        self::assertSame('_top', $profile->attr('data-turbo-frame'));
    }

    public function testADirectVisitIsANoindexPageWithTheProfileAsCanonical(): void
    {
        $browser = $this->browser();

        $crawler = $browser->request('GET', '/en/puzzler-card/' . PlayerFixture::PLAYER_REGULAR);

        self::assertResponseIsSuccessful();
        self::assertSame('noindex, follow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertStringEndsWith('/en/player-profile/' . PlayerFixture::PLAYER_REGULAR, (string) $crawler->filter('link[rel="canonical"]')->attr('href'));
        self::assertCount(1, $crawler->filter('h1.players-card-page-title'));
        self::assertCount(1, $crawler->filter('turbo-frame#player-card .players-card'));
        self::assertStringContainsString('Turbo-Frame', (string) $browser->getResponse()->headers->get('Vary'));
    }

    public function testAGuestSeesTheActivityAndTheTierLocked(): void
    {
        $browser = $this->browser();
        $this->rate(PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR);

        self::assertCount(0, $crawler->filter('.players-card-bars[role="img"]'), 'No activity numbers for a guest');
        self::assertCount(1, $crawler->filter('.players-card-locked button.players-card-unlock[data-bs-target="#membersExclusiveModal"]'));
        self::assertStringContainsString('Members exclusive', $crawler->filter('.players-card-unlock')->text());

        self::assertStringContainsString('MSP Rating 873', $crawler->filter('.players-card-chips')->text(), 'The rating is public');
        self::assertCount(1, $crawler->filter('button.players-card-chip-locked[data-bs-target="#membersExclusiveModal"]'));
        self::assertCount(0, $crawler->filter('.players-card-chip-tier'));

        // Favorite and Compare ask to sign in first, then land on the profile
        $favorite = $crawler->filter('a.players-card-favorite');
        self::assertSame('/en/add-player-to-favorites/' . PlayerFixture::PLAYER_REGULAR . '?return=/en/player-profile/' . PlayerFixture::PLAYER_REGULAR, rawurldecode((string) $favorite->attr('href')));
        self::assertSame('_top', $favorite->attr('data-turbo-frame'));
        self::assertCount(0, $crawler->filter('form[action="/en/compare/add"]'));
        self::assertCount(1, $crawler->filter('.players-card-actions a[href^="/login?return="][data-turbo-frame="_top"]'));
    }

    public function testANonMemberSeesTheActivityLockedToo(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->card($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertCount(0, $crawler->filter('.players-card-bars[role="img"]'));
        self::assertCount(1, $crawler->filter('.players-card-locked'));
    }

    public function testAMemberSeesTheActivityAndTheTier(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        $this->rate(PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR);

        self::assertCount(0, $crawler->filter('.players-card-locked'));
        $strip = $crawler->filter('.players-card-bars[role="img"]');
        self::assertCount(1, $strip);
        self::assertCount(12, $strip->filter('i'));
        self::assertStringStartsWith('Puzzles per month: ', (string) $strip->attr('aria-label'));

        self::assertStringContainsString('Expert', $crawler->filter('.players-card-chip-tier')->text());
        self::assertCount(0, $crawler->filter('.players-card-chip-locked'));
    }

    public function testARankingOptOutShowsNeitherRatingNorTier(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        $this->rate(PlayerFixture::PLAYER_REGULAR);
        $this->database()->executeStatement('UPDATE player SET ranking_opted_out = true WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]);

        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('.players-card-chip-tier'));
        self::assertStringNotContainsString('MSP Rating', (string) $browser->getResponse()->getContent());

        // Not even the lock for a guest
        $browser->getCookieJar()->clear();
        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR);
        self::assertCount(0, $crawler->filter('.players-card-chip-locked'));
    }

    public function testAPlayerTheViewerBlockedHasNoCard(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        $this->database()->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => PlayerFixture::PLAYER_WITH_FAVORITES, 'blocked' => PlayerFixture::PLAYER_WITH_STRIPE],
        );

        // A bystander sees it
        $this->card($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertResponseIsSuccessful();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $crawler = $this->card($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertResponseStatusCodeSame(404);
        self::assertCount(1, $crawler->filter('turbo-frame#player-card .players-card-unavailable'));
        self::assertStringNotContainsString(PlayerFixture::PLAYER_WITH_STRIPE_NAME, (string) $browser->getResponse()->getContent());

        $browser->request('GET', '/en/puzzler-card/' . PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertResponseStatusCodeSame(404);
    }

    public function testAPrivatePlayerHasACardOnlyForTheAllowedFriendAndThemselves(): void
    {
        $browser = $this->browser();

        $crawler = $this->card($browser, PlayerFixture::PLAYER_PRIVATE);
        self::assertResponseStatusCodeSame(404);
        self::assertCount(1, $crawler->filter('.players-card-unavailable'));
        self::assertStringNotContainsString('Jane Smith', (string) $browser->getResponse()->getContent());

        $browser->request('GET', '/en/puzzler-card/' . PlayerFixture::PLAYER_PRIVATE);
        self::assertResponseStatusCodeSame(404);

        // A signed-in stranger (a member)
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->card($browser, PlayerFixture::PLAYER_PRIVATE);
        self::assertResponseStatusCodeSame(404);

        // On her allow list
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $crawler = $this->card($browser, PlayerFixture::PLAYER_PRIVATE);
        self::assertResponseIsSuccessful();
        self::assertSame('Jane Smith', trim($crawler->filter('#players-card-name')->text()));
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));

        // Her own card
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);
        $crawler = $this->card($browser, PlayerFixture::PLAYER_PRIVATE);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $crawler->filter('.players-card-actions.is-own'));
        self::assertCount(0, $crawler->filter('.players-card-favorite'));
        self::assertCount(0, $crawler->filter('form[action="/en/compare/add"]'));
    }

    public function testAnUnknownPlayerIsNotFound(): void
    {
        $browser = $this->browser();

        $this->card($browser, Uuid::uuid7()->toString());
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/en/puzzler-card/not-a-uuid');
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheCardCostsTwoStatementsForAGuest(): void
    {
        $browser = $this->browser();

        $this->startCountingQueries($browser);
        $this->card($browser, PlayerFixture::PLAYER_REGULAR);

        self::assertResponseIsSuccessful();
        // The profile + the card's numbers
        $this->assertQueryCountAtMost($browser, 2, 'Player card for a guest');
    }

    public function testFavoriteTogglesInsideTheFrame(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_REGULAR);
        $cardPath = '/en/puzzler-card/' . PlayerFixture::PLAYER_WITH_STRIPE;

        $crawler = $this->card($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $favorite = $crawler->filter('a.players-card-favorite');
        self::assertSame('Add to favorites', $favorite->attr('aria-label'));
        self::assertNull($favorite->attr('data-turbo-frame'), 'Stays in the frame');
        $href = (string) $favorite->attr('href');
        self::assertSame('/en/add-player-to-favorites/' . PlayerFixture::PLAYER_WITH_STRIPE . '?return=' . $cardPath, rawurldecode($href));

        // Turbo follows the link inside the frame: back to the card, now in favorites, with what happened
        $browser->request('GET', $href, server: ['HTTP_TURBO_FRAME' => self::FRAME]);
        self::assertResponseRedirects($cardPath);
        $crawler = $browser->followRedirect();
        self::assertStringStartsWith('<turbo-frame id="player-card">', trim((string) $browser->getResponse()->getContent()));
        self::assertCount(1, $crawler->filter('a.players-card-favorite.is-on[aria-label="Remove from favorites"]'));
        self::assertStringContainsString('added the puzzler to your list of favorites', $crawler->filter('.players-card-notice[role="status"]')->text());

        // And out again
        $browser->request('GET', (string) $crawler->filter('a.players-card-favorite')->attr('href'), server: ['HTTP_TURBO_FRAME' => self::FRAME]);
        $crawler = $browser->followRedirect();
        self::assertCount(0, $crawler->filter('a.players-card-favorite.is-on'));
        self::assertStringContainsString('removed the puzzler', $crawler->filter('.players-card-notice')->text());
    }

    public function testCompareAddsInsideTheFrame(): void
    {
        // PLAYER_ADMIN's line-ups are empty
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        $cardPath = '/en/puzzler-card/' . PlayerFixture::PLAYER_REGULAR;

        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR);
        $form = $crawler->filter('.players-card-actions form[action="/en/compare/add"]');
        self::assertCount(1, $form);
        self::assertSame('p-' . PlayerFixture::PLAYER_REGULAR, $form->filter('input[name="subject"]')->attr('value'));
        self::assertSame($cardPath, $form->filter('input[name="return"]')->attr('value'));

        $browser->request('POST', '/en/compare/add', [
            '_token' => 'csrf-token',
            'subject' => 'p-' . PlayerFixture::PLAYER_REGULAR,
            'return' => $cardPath,
        ], server: ['HTTP_ORIGIN' => 'http://localhost', 'HTTP_TURBO_FRAME' => self::FRAME]);
        self::assertResponseRedirects($cardPath);

        $crawler = $browser->followRedirect();
        self::assertCount(0, $crawler->filter('form[action="/en/compare/add"]'));
        $open = $crawler->filter('.players-card-actions a[href="/en/compare?kind=solo"]');
        self::assertCount(1, $open);
        self::assertSame('_top', $open->attr('data-turbo-frame'));
        self::assertStringContainsString('John Doe added to your comparison.', $crawler->filter('.players-card-notice')->text());
    }

    private function browser(null|string $viewerId = null): KernelBrowser
    {
        $browser = self::createClient();
        (self::getContainer()->get(RecalculateCommunityStatsHandler::class))(new RecalculateCommunityStats());

        if ($viewerId !== null) {
            TestingLogin::asPlayer($browser, $viewerId);
        }

        return $browser;
    }

    private function card(KernelBrowser $browser, string $playerId): Crawler
    {
        return $browser->request('GET', '/en/puzzler-card/' . $playerId, server: ['HTTP_TURBO_FRAME' => self::FRAME]);
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    /**
     * MSP Rating 873 and the Expert tier on 500 pieces
     */
    private function rate(string $playerId): void
    {
        $database = $this->database();
        $database->executeStatement(
            'INSERT INTO player_elo (id, player_id, pieces_count, elo_rating, computed_at) VALUES (:id, :player, 500, 0.8734, NOW())',
            ['id' => Uuid::uuid7()->toString(), 'player' => $playerId],
        );
        $database->executeStatement(
            "INSERT INTO player_skill (id, player_id, pieces_count, skill_score, skill_tier, skill_percentile, confidence, qualifying_puzzles_count, computed_at)
             VALUES (:id, :player, 500, 0.9, :tier, 90.0, 'high', 12, NOW())",
            ['id' => Uuid::uuid7()->toString(), 'player' => $playerId, 'tier' => SkillTier::Expert->value],
        );
    }
}
