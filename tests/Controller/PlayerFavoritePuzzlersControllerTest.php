<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The favorite puzzlers page is personal: the owner's favorites and who has the owner in favorites.
 *
 * PlayerFixture: PLAYER_WITH_FAVORITES follows PLAYER_REGULAR and PLAYER_ADMIN; nobody else follows anybody.
 * PrivateProfileViewerFixture: PLAYER_PRIVATE ("Jane Smith", #PLAYER2) allows PLAYER_WITH_FAVORITES only.
 * UserBlockFixture: PLAYER_REGULAR blocks PLAYER_PRIVATE.
 */
final class PlayerFavoritePuzzlersControllerTest extends WebTestCase
{
    public function testOwnerGetsTheirFavoritesAndWhoHasThemInFavorites(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $crawler = $browser->request('GET', '/en/player-favorites/' . PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('#fp-favorites-title', 'Your favorites');
        self::assertSelectorTextContains('#fp-followers-title', 'Who has you in favorites');

        // Ordered by name: "Admin User" before "John Doe"
        self::assertSame(['Admin User', 'John Doe'], $crawler->filter('section[aria-labelledby="fp-favorites-title"] .fp-name')->each(
            static fn ($node): string => trim($node->text()),
        ));

        // Nobody has Michael in favorites
        self::assertSelectorTextContains('section[aria-labelledby="fp-followers-title"]', 'Nobody has added you to their favorites yet.');

        // Personal, never indexed, and its head does not speak about "somebody's" favorites
        self::assertSame('noindex, nofollow', $crawler->filter('meta[name="robots"]')->attr('content'));
        self::assertStringStartsWith('Your favorite puzzlers', $crawler->filter('title')->text());
        self::assertStringNotContainsString('Michael Johnson', (string) $crawler->filter('meta[name="description"]')->attr('content'));
    }

    public function testAnotherPlayerIsSentToTheProfile(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/player-favorites/' . PlayerFixture::PLAYER_WITH_FAVORITES);

        self::assertResponseStatusCodeSame(302);
        self::assertResponseRedirects('/en/player-profile/' . PlayerFixture::PLAYER_WITH_FAVORITES);
    }

    public function testGuestIsSentToTheProfile(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/player-favorites/' . PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertResponseStatusCodeSame(302);
        self::assertResponseRedirects('/en/player-profile/' . PlayerFixture::PLAYER_WITH_FAVORITES);

        $browser->request('GET', '/oblibeni-hraci/' . PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertResponseStatusCodeSame(302);
        self::assertResponseRedirects('/profil-hrace/' . PlayerFixture::PLAYER_WITH_FAVORITES);
    }

    public function testFollowersListAPublicFollowerLeaveOutABlockedOneAndOnlyCountAHiddenPrivateOne(): void
    {
        $browser = self::createClient();

        // PLAYER_ADMIN is followed by PLAYER_WITH_FAVORITES (fixture), by the private Jane (who did not allow the admin)
        // and by Sarah, whom the admin blocks
        $this->follow(PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_ADMIN);
        $this->follow(PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_ADMIN);
        $this->block(PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_WITH_STRIPE);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $crawler = $browser->request('GET', '/en/player-favorites/' . PlayerFixture::PLAYER_ADMIN);
        self::assertResponseIsSuccessful();

        $followers = $crawler->filter('section[aria-labelledby="fp-followers-title"]');
        self::assertSame([PlayerFixture::PLAYER_WITH_FAVORITES_NAME, '+ 1 private puzzler'], $followers->filter('.fp-name')->each(
            static fn ($node): string => trim($node->text()),
        ));
        self::assertSame('2', $followers->filter('.fp-count')->text());

        $content = (string) $browser->getResponse()->getContent();

        // The blocked follower is nowhere
        self::assertStringNotContainsString(PlayerFixture::PLAYER_WITH_STRIPE, $content);
        self::assertStringNotContainsString(PlayerFixture::PLAYER_WITH_STRIPE_NAME, $content);

        // The hidden private follower is a number: no name, no id, no code
        self::assertStringNotContainsString('Jane Smith', $content);
        self::assertStringNotContainsString(PlayerFixture::PLAYER_PRIVATE, $content);
        self::assertStringNotContainsString('PLAYER2', $content);
    }

    public function testOnlyPrivateFollowersAreCountedWithoutAList(): void
    {
        $browser = self::createClient();
        $this->follow(PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_WITH_STRIPE);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', '/en/player-favorites/' . PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertResponseIsSuccessful();

        self::assertSame(['1 private puzzler'], $crawler->filter('section[aria-labelledby="fp-followers-title"] .fp-name')->each(
            static fn ($node): string => trim($node->text()),
        ));
        self::assertStringNotContainsString('Jane Smith', (string) $browser->getResponse()->getContent());
    }

    public function testAPrivateFollowerWhoAllowsTheOwnerIsListedByName(): void
    {
        $browser = self::createClient();
        $this->follow(PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_WITH_FAVORITES);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $crawler = $browser->request('GET', '/en/player-favorites/' . PlayerFixture::PLAYER_WITH_FAVORITES);
        self::assertResponseIsSuccessful();

        self::assertSame(['Jane Smith'], $crawler->filter('section[aria-labelledby="fp-followers-title"] .fp-name')->each(
            static fn ($node): string => trim($node->text()),
        ));
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
    }

    public function testABlockedPrivateFollowerIsNotEvenCounted(): void
    {
        $browser = self::createClient();
        // UserBlockFixture: PLAYER_REGULAR blocks PLAYER_PRIVATE
        $this->follow(PlayerFixture::PLAYER_PRIVATE, PlayerFixture::PLAYER_REGULAR);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/player-favorites/' . PlayerFixture::PLAYER_REGULAR);
        self::assertResponseIsSuccessful();

        self::assertSame([PlayerFixture::PLAYER_WITH_FAVORITES_NAME], $crawler->filter('section[aria-labelledby="fp-followers-title"] .fp-name')->each(
            static fn ($node): string => trim($node->text()),
        ));
        self::assertStringNotContainsString('private puzzler', (string) $browser->getResponse()->getContent());
    }

    public function testAFollowedPrivatePlayerStaysInTheFavoritesByCodeOnly(): void
    {
        $browser = self::createClient();
        $this->follow(PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_PRIVATE);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $this->ownPage($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $favorites = $crawler->filter('section[aria-labelledby="fp-favorites-title"]');
        self::assertSame('Hidden Puzzler', trim($favorites->filter('.fp-name')->text()));
        self::assertSame('#PLAYER2', trim($favorites->filter('.fp-code')->text()));
        // Still a way to their profile - that is where they are removed from favorites
        self::assertCount(1, $favorites->filter('a[href="/en/player-profile/' . PlayerFixture::PLAYER_PRIVATE . '"]'));
        self::assertStringNotContainsString('Jane Smith', (string) $browser->getResponse()->getContent());
    }

    public function testEmptyStates(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $this->ownPage($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertCount(0, $crawler->filter('.fp-list'));
        self::assertSelectorTextContains('section[aria-labelledby="fp-favorites-title"]', 'You have no favorite puzzlers yet.');
        self::assertSelectorExists('section[aria-labelledby="fp-favorites-title"] a[href="/en/puzzlers"]');
        self::assertSelectorTextContains('section[aria-labelledby="fp-followers-title"]', 'Nobody has added you to their favorites yet.');
    }

    private function ownPage(KernelBrowser $browser, string $playerId): Crawler
    {
        $crawler = $browser->request('GET', '/en/player-favorites/' . $playerId);
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function follow(string $followerId, string $followedId): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE player SET favorite_players = (favorite_players::jsonb || jsonb_build_array(:followed::text))::json WHERE id = :follower',
            ['followed' => $followedId, 'follower' => $followerId],
        );
    }

    private function block(string $blockerId, string $blockedId): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source) VALUES (:id, :blocker, :blocked, NOW(), 'self')",
            ['id' => Uuid::uuid7()->toString(), 'blocker' => $blockerId, 'blocked' => $blockedId],
        );
    }
}
