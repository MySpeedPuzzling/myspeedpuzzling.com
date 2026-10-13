<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use DOMElement;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\NavClickController;
use SpeedPuzzling\Web\Message\RecalculateCommunityStats;
use SpeedPuzzling\Web\MessageHandler\RecalculateCommunityStatsHandler;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestDouble\FixedXpLaunchReadiness;
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
        self::assertStringContainsString('Competes in events', $crawler->filter('.players-card-chips')->last()->text());

        // The name is the profile link and the card's first stop (CARD-03); leaving the card leaves the frame
        $profile = $crawler->filter('#players-card-name a');
        self::assertSame('/en/player-profile/' . PlayerFixture::PLAYER_REGULAR, $profile->attr('href'));
        self::assertSame('_top', $profile->attr('data-turbo-frame'));
        self::assertSame('card/none/profile', $crawler->filter('a, button, summary')->first()->attr('data-nav'));
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
        // Before the XP launch (the switch off): only admins see progression
        FixedXpLaunchReadiness::$ready = false;
        $browser = $this->browser();
        $this->rate(PlayerFixture::PLAYER_REGULAR);

        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR);

        self::assertCount(0, $crawler->filter('.players-card-bars[role="img"]'), 'No activity numbers for a guest');
        self::assertCount(1, $crawler->filter('.players-card-locked button.players-card-unlock[data-bs-target="#membersExclusiveModal"]'));
        self::assertStringContainsString('Members exclusive', $crawler->filter('.players-card-unlock')->text());

        // Progression on the card = the level and the tier only (docs/features/xp-levels/launch-2026-10.md); the level
        // follows XpFeatureGate (launch ready or admin)
        self::assertCount(0, $crawler->filter('.players-card-chip-level'));
        self::assertStringNotContainsString('MSP Rating', (string) $browser->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('button.players-card-chip-locked[data-bs-target="#membersExclusiveModal"]'));
        self::assertCount(0, $crawler->filter('.players-card-chip-tier'));

        // Favorite and Compare ask to sign in first, then land on the profile - in the layer on the page the card was
        // opened on (data-card-return-page: the layer rewrites `return`, the response stays the same for every guest)
        $favorite = $crawler->filter('a.players-card-favorite');
        self::assertSame('/en/add-player-to-favorites/' . PlayerFixture::PLAYER_REGULAR . '?return=/en/player-profile/' . PlayerFixture::PLAYER_REGULAR, rawurldecode((string) $favorite->attr('href')));
        self::assertSame('_top', $favorite->attr('data-turbo-frame'));
        self::assertNotNull($favorite->attr('data-card-return-page'));
        self::assertCount(0, $crawler->filter('form[action="/en/compare/add"]'));
        self::assertCount(1, $crawler->filter('.players-card-actions a[href^="/login?return="][data-turbo-frame="_top"][data-card-return-page]'));
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

    public function testTheLevelFollowsTheExperienceOptOut(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        $this->database()->executeStatement('UPDATE player SET xp_total = 7000, level = 50 WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]);

        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR);
        $level = $crawler->filter('.players-card-chip-level');
        self::assertSame('Lv 50', trim($level->text()));
        self::assertStringContainsString('is-max', (string) $level->attr('class'));

        $this->database()->executeStatement('UPDATE player SET experience_system_opted_out = true WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]);
        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR);
        self::assertCount(0, $crawler->filter('.players-card-chip-level'));
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
        self::assertCount(0, $crawler->filter('.players-card-message'));
        // ⋯ = Share on every card
        self::assertCount(1, $crawler->filter('.players-card-more button[data-controller="share-link"]'));
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

    /**
     * CARD-03 (README §4.8): the player's pages Overview · Results · Statistics, then Favorite · Compare · Message · ⋯
     * (Share) for a member, a non-member and a guest - Message only when the player may be messaged; no Block (D12),
     * no "Challenge" (reserved). Every link and button carries a card/<kind>/<item> data-nav id (P0-03).
     *
     * @return iterable<string, array{null|string, string, list<string>}>
     */
    public static function viewers(): iterable
    {
        // viewer, the card's player, the data-nav ids of the card in order (the latest puzzles' card/none/puzzle aside)
        $pages = ['card/none/overview', 'card/none/results', 'card/none/statistics'];

        yield 'member' => [PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_REGULAR, ['card/none/profile', 'card/none/close', ...$pages, 'card/none/favorite', 'card/none/compare', 'card/none/message', 'card/none/share']];
        yield 'non-member' => [PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_WITH_STRIPE, ['card/none/profile', 'card/none/close', 'card/none/tier_locked', 'card/none/unlock', ...$pages, 'card/none/favorite', 'card/none/compare', 'card/none/message', 'card/none/share']];
        yield 'guest' => [null, PlayerFixture::PLAYER_WITH_STRIPE, ['card/none/profile', 'card/none/close', 'card/none/tier_locked', 'card/none/unlock', ...$pages, 'card/none/favorite', 'card/none/compare', 'card/none/share']];
    }

    /**
     * @param list<string> $navIds
     */
    #[DataProvider('viewers')]
    public function testTheCardsPagesAndActions(null|string $viewerId, string $playerId, array $navIds): void
    {
        $browser = $this->browser($viewerId);

        $crawler = $this->card($browser, $playerId);
        self::assertResponseIsSuccessful();

        $pages = $crawler->filter('nav.players-card-pages a');
        self::assertSame(
            ['/en/player-profile/' . $playerId, '/en/player-results/' . $playerId, '/en/player-statistics/' . $playerId],
            $pages->each(static fn (Crawler $link): string => (string) $link->attr('href')),
        );
        self::assertSame(['Overview', 'Results', 'Statistics'], $pages->each(static fn (Crawler $link): string => trim($link->text())));
        self::assertSame(['_top', '_top', '_top'], $pages->each(static fn (Crawler $link): string => (string) $link->attr('data-turbo-frame')));
        self::assertSame('nofollow', $pages->last()->attr('rel'));

        $message = $crawler->filter('.players-card-actions a.players-card-message');
        self::assertCount($viewerId === null ? 0 : 1, $message, 'Message: signed-in viewers, when the player may be messaged');

        if ($viewerId !== null) {
            self::assertSame('/en/messages/new/' . $playerId, $message->attr('href'));
            self::assertSame('modal-frame', $message->attr('data-turbo-frame'));
        }

        // ⋯ holds Share; Block stays on the profile, Challenge is not built
        self::assertCount(1, $crawler->filter('.players-card-actions details.players-card-more summary[aria-label="More actions"]'));
        self::assertCount(1, $crawler->filter('.players-card-more button[data-controller="share-link"]'));
        $html = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString('blockPlayerModal', $html);
        self::assertStringNotContainsString('/block-user/', $html);
        self::assertStringNotContainsStringIgnoringCase('challenge', $html);

        $ids = [];

        foreach ($crawler->filter('a, button') as $element) {
            assert($element instanceof DOMElement);
            $value = $element->getAttribute('data-nav');
            self::assertMatchesRegularExpression(NavClickController::NAV_PATTERN, $value, trim($element->textContent));

            if ($value !== 'card/none/puzzle') {
                $ids[] = $value;
            }
        }

        self::assertSame($navIds, $ids);
        self::assertCount(
            $crawler->filter('.players-card-recent a')->count(),
            $crawler->filter('.players-card-recent a[data-nav="card/none/puzzle"]'),
        );

        if ($viewerId === null) {
            // A guest's card is the same for every guest: no session, and the card's own page stays shared-cacheable
            // (the sign-in links' return to the opener page is the layer's job, decision 73)
            self::assertSame([], $browser->getResponse()->headers->getCookies());
            $browser->request('GET', '/en/puzzler-card/' . $playerId);
            self::assertStringContainsString('public', (string) $browser->getResponse()->headers->get('Cache-Control'));
        }
    }

    public function testMessageOnlyWhenThePlayerMayBeMessaged(): void
    {
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        $database = $this->database();
        $database->executeStatement('UPDATE player SET allow_direct_messages = false WHERE id = :id', ['id' => PlayerFixture::PLAYER_REGULAR]);
        // No accepted conversation between the two (a pending request does not count)
        $database->executeStatement(
            "UPDATE conversation SET status = 'pending' WHERE (initiator_id = :a AND recipient_id = :b) OR (initiator_id = :b AND recipient_id = :a)",
            ['a' => PlayerFixture::PLAYER_ADMIN, 'b' => PlayerFixture::PLAYER_REGULAR],
        );

        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR);
        self::assertCount(0, $crawler->filter('.players-card-message'), 'Direct messages off, no conversation');

        $database->executeStatement(
            "INSERT INTO conversation (id, initiator_id, recipient_id, status, created_at, responded_at, last_message_at) VALUES (:id, :a, :b, 'accepted', NOW(), NOW(), NOW())",
            ['id' => Uuid::uuid7()->toString(), 'a' => PlayerFixture::PLAYER_REGULAR, 'b' => PlayerFixture::PLAYER_ADMIN],
        );

        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR);
        self::assertCount(1, $crawler->filter('.players-card-message'), 'An accepted conversation keeps it open');
    }

    public function testTheContextTravelsWithTheCardAndAnInvalidOneIsIgnored(): void
    {
        // PLAYER_ADMIN's comparison line-ups are empty: Compare is the add form
        $browser = $this->browser(PlayerFixture::PLAYER_ADMIN);
        $context = 'puzzle:' . Uuid::uuid7()->toString();
        $cardPath = '/en/puzzler-card/' . PlayerFixture::PLAYER_REGULAR;

        $crawler = $browser->request('GET', $cardPath . '?context=' . rawurlencode($context), server: ['HTTP_TURBO_FRAME' => self::FRAME]);
        self::assertResponseIsSuccessful();

        // Favorite comes back to the card with its context (decision 73)
        $favorite = (string) $crawler->filter('a.players-card-favorite')->attr('href');
        parse_str((string) parse_url($favorite, PHP_URL_QUERY), $query);
        self::assertSame($cardPath . '?context=' . $context, $query['return'] ?? null);
        // Compare posts the card's own address, context included
        self::assertSame($cardPath . '?context=' . rawurlencode($context), $crawler->filter('form[action="/en/compare/add"] input[name="return"]')->attr('value'));
        self::assertSame('card/puzzle/profile', $crawler->filter('#players-card-name a')->attr('data-nav'));

        // Anything else is no context: the card as without one, never an error
        foreach (['puzzle:not-a-uuid', 'whatever', '<script>', 'leaderboard:times-500:cze'] as $invalid) {
            $crawler = $browser->request('GET', $cardPath . '?context=' . rawurlencode($invalid), server: ['HTTP_TURBO_FRAME' => self::FRAME]);
            self::assertResponseIsSuccessful($invalid);
            parse_str((string) parse_url((string) $crawler->filter('a.players-card-favorite')->attr('href'), PHP_URL_QUERY), $query);
            self::assertSame($cardPath, $query['return'] ?? null, $invalid);
            self::assertSame('card/none/profile', $crawler->filter('#players-card-name a')->attr('data-nav'), $invalid);
        }

        $crawler = $browser->request('GET', $cardPath . '?context=' . rawurlencode('leaderboard:times-500:cz'), server: ['HTTP_TURBO_FRAME' => self::FRAME]);
        self::assertSame('card/leaderboard/profile', $crawler->filter('#players-card-name a')->attr('data-nav'));
    }

    public function testTheLatestPuzzlesLinkTheirPages(): void
    {
        $browser = $this->browser();
        // TIME_41 (PUZZLE_1000_03, the pair with PLAYER_PRIVATE) becomes PLAYER_REGULAR's newest result
        $this->database()->executeStatement(
            'UPDATE puzzle_solving_time SET tracked_at = NOW(), finished_at = NOW() WHERE id = :id',
            ['id' => PuzzleSolvingTimeFixture::TIME_41],
        );

        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR);
        $boxes = $crawler->filter('.players-card-recent a.players-card-puzzle');
        self::assertGreaterThanOrEqual(1, $boxes->count());
        self::assertLessThanOrEqual(3, $boxes->count());
        self::assertSame('Latest puzzles', trim($crawler->filter('.players-card-recent h3')->text()));
        self::assertSame('/en/puzzle/' . PuzzleFixture::PUZZLE_1000_03, $boxes->first()->attr('href'));
        self::assertSame('_top', $boxes->first()->attr('data-turbo-frame'));
        self::assertNotSame('', (string) $boxes->first()->filter('img')->attr('alt'));

        // Awaiting verification: not one of the latest puzzles any more (unless solved again some other way)
        $this->database()->executeStatement('UPDATE puzzle_solving_time SET suspicious = true WHERE puzzle_id = :id', ['id' => PuzzleFixture::PUZZLE_1000_03]);
        $crawler = $this->card($browser, PlayerFixture::PLAYER_REGULAR);
        self::assertNotContains(
            '/en/puzzle/' . PuzzleFixture::PUZZLE_1000_03,
            $crawler->filter('.players-card-recent a')->each(static fn (Crawler $link): string => (string) $link->attr('href')),
        );
    }

    public function testTheCardsOwnPageLeadsBackToTheProfile(): void
    {
        $browser = $this->browser();

        $crawler = $browser->request('GET', '/en/puzzler-card/' . PlayerFixture::PLAYER_REGULAR);
        $back = $crawler->filter('a.players-card-page-back');
        self::assertSame('/en/player-profile/' . PlayerFixture::PLAYER_REGULAR, $back->attr('href'));
        self::assertSame('View profile', trim($back->text()));
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
