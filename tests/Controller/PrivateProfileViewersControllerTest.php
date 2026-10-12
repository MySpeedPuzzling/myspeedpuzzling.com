<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;

/**
 * The private profile's allow list, end to end through HTTP
 * (docs/features/private-profile-allow-list.md).
 */
final class PrivateProfileViewersControllerTest extends WebTestCase
{
    use InteractsWithLiveComponents;

    private const string URL = '/en/who-can-see-my-profile';

    public function testGuestIsSentToLogin(): void
    {
        $browser = self::createClient();
        $browser->request('GET', self::URL);

        self::assertResponseRedirects();
    }

    public function testOwnerAllowsAPlayerWhoThenSeesThemAndRemovesThemAgain(): void
    {
        $browser = self::createClient();
        $this->makeMember($browser->getContainer()->get(Connection::class), PlayerFixture::PLAYER_PRIVATE);
        $strangerSees = fn (): string => $this->profileAs($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertStringNotContainsString('Jane Smith', $strangerSees());

        // Add through the picker form
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);
        $crawler = $browser->request('GET', self::URL);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('.list-group', 'Michael Johnson');

        $form = $crawler->selectButton('Add')->form();
        $form['allow_private_profile_viewers_form[players]'] = PlayerFixture::PLAYER_WITH_STRIPE;
        $browser->submit($form);
        self::assertResponseRedirects(self::URL);

        self::assertStringContainsString('Jane Smith', $strangerSees());

        // ...and remove
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);
        $crawler = $browser->request('GET', self::URL);
        $removeForm = $crawler->filter('form[action$="/' . PlayerFixture::PLAYER_WITH_STRIPE . '/remove"]')->form();
        $browser->submit($removeForm);
        self::assertResponseRedirects(self::URL);

        self::assertStringNotContainsString('Jane Smith', $strangerSees());
    }

    public function testSubmittingNobodyIsAnswered422NotA200TurboWouldDrop(): void
    {
        $browser = self::createClient();
        $this->makeMember($browser->getContainer()->get(Connection::class), PlayerFixture::PLAYER_PRIVATE);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        $crawler = $browser->request('GET', self::URL);
        $browser->submit($crawler->selectButton('Add')->form());

        self::assertResponseStatusCodeSame(422);
    }

    public function testPostsWithoutACsrfTokenAreRefused(): void
    {
        $browser = self::createClient();
        $this->makeMember($browser->getContainer()->get(Connection::class), PlayerFixture::PLAYER_PRIVATE);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);

        $browser->request('POST', self::URL . '/' . PlayerFixture::PLAYER_ADMIN . '/allow');
        self::assertResponseStatusCodeSame(403);

        $browser->request('POST', self::URL . '/' . PlayerFixture::PLAYER_WITH_FAVORITES . '/remove');
        self::assertResponseStatusCodeSame(403);

        $allowed = $browser->getContainer()->get(Connection::class)
            ->executeQuery('SELECT viewer_id FROM private_profile_viewer WHERE owner_id = :id', ['id' => PlayerFixture::PLAYER_PRIVATE])
            ->fetchFirstColumn();
        self::assertSame([PlayerFixture::PLAYER_WITH_FAVORITES], $allowed);
    }

    public function testNonMemberIsSentBackToSettings(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::URL);

        self::assertResponseRedirects('/en/edit-profile');
    }

    /**
     * Her new times reach the followers she allowed on Home › Favorites: favorites' times are no notifications any more
     * (docs/features/navigation INB-03). The friend sees her there while allowed; a follower she never allowed learns
     * nothing (her solo times are not in their feed, she is masked in her pair's); taken off the list, the friend loses
     * her too.
     */
    public function testHerTimesOnHubFavoritesNameThePrivatePlayerForTheFriendOnlyWhileTheyAreAllowed(): void
    {
        $browser = self::createClient();
        $database = $browser->getContainer()->get(Connection::class);

        // Both follow her (and only her): her solo times and the pair times she is in fill the feed
        $database->executeStatement(
            'UPDATE player SET favorite_players = CAST(:favorites AS json) WHERE id IN (:friend, :stranger)',
            ['favorites' => json_encode([PlayerFixture::PLAYER_PRIVATE]), 'friend' => PlayerFixture::PLAYER_WITH_FAVORITES, 'stranger' => PlayerFixture::PLAYER_WITH_STRIPE],
        );

        self::assertStringContainsString('Jane Smith', $this->favoritesFeedAs($browser, PlayerFixture::PLAYER_WITH_FAVORITES));

        // Somebody she never allowed learns nothing from following her
        $content = $this->favoritesFeedAs($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertStringNotContainsString('Jane Smith', $content);
        self::assertStringContainsString('Hidden Puzzler', $content);

        // Taken off the list, the friend does not see her any more
        $database->executeStatement('DELETE FROM private_profile_viewer');
        self::assertStringNotContainsString('Jane Smith', $this->favoritesFeedAs($browser, PlayerFixture::PLAYER_WITH_FAVORITES));
    }

    /**
     * Home › Favorites: the Hub's Favorites pane (loaded lazily on the page, so rendered here as its Live component)
     */
    private function favoritesFeedAs(KernelBrowser $browser, string $viewerId): string
    {
        TestingLogin::asPlayer($browser, $viewerId);

        $component = $this->createLiveComponent('RecentActivity', ['limit' => 20, 'favoritesOnly' => true], $browser);
        $component->setRouteLocale('en');

        return $component->render()->toString();
    }

    private function profileAs(KernelBrowser $browser, string $viewerId): string
    {
        TestingLogin::asPlayer($browser, $viewerId);
        $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_PRIVATE);
        self::assertResponseIsSuccessful();

        return (string) $browser->getResponse()->getContent();
    }

    private function makeMember(Connection $database, string $playerId): void
    {
        $database->executeStatement(
            "INSERT INTO membership (id, player_id, created_at, granted_until) VALUES (:id, :player, NOW(), NOW() + INTERVAL '1 year')",
            ['id' => Uuid::uuid7()->toString(), 'player' => $playerId],
        );
    }
}
