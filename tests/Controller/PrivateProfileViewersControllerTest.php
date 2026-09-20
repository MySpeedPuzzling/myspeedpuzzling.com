<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleSolvingTimeFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The private profile's allow list, end to end through HTTP
 * (docs/features/private-profile-allow-list.md).
 */
final class PrivateProfileViewersControllerTest extends WebTestCase
{
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

    public function testASolvedPuzzleNotificationNamesThePrivatePlayerForTheFriendOnlyWhileTheyAreAllowed(): void
    {
        $browser = self::createClient();
        $database = $browser->getContainer()->get(Connection::class);

        // TIME_44: a solo time of the private player
        foreach ([PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_WITH_STRIPE] as $recipient) {
            $database->executeStatement(
                "INSERT INTO notification (id, player_id, type, notified_at, target_solving_time_id) VALUES (:id, :player, 'subscribed_player_added_time', NOW(), :time)",
                ['id' => Uuid::uuid7()->toString(), 'player' => $recipient, 'time' => PuzzleSolvingTimeFixture::TIME_44],
            );
        }

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $browser->request('GET', '/en/notifications');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Jane Smith', (string) $browser->getResponse()->getContent());

        // Somebody who should never have got the row still learns nothing from it
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', '/en/notifications');
        self::assertResponseIsSuccessful();
        $content = (string) $browser->getResponse()->getContent();
        self::assertStringNotContainsString('Jane Smith', $content);
        self::assertStringContainsString('Hidden Puzzler', $content);

        // Taken off the list, the friend's old notification is masked again
        $database->executeStatement('DELETE FROM private_profile_viewer');
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $browser->request('GET', '/en/notifications');
        self::assertStringNotContainsString('Jane Smith', (string) $browser->getResponse()->getContent());
    }

    private function profileAs(\Symfony\Bundle\FrameworkBundle\KernelBrowser $browser, string $viewerId): string
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
