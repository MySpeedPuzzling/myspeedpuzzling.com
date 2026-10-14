<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Query\GetUserBlocks;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * UserBlockFixture: PLAYER_REGULAR blocks PLAYER_PRIVATE. See docs/features/player-blocklist.md.
 */
final class PlayerBlocklistTest extends WebTestCase
{
    public function testBlockedPlayersProfileDoesNotExistForTheBlocker(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_PRIVATE);
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_ADMIN);
        self::assertResponseIsSuccessful();
    }

    public function testBlockIsInvisibleToEveryoneElse(): void
    {
        $browser = self::createClient();

        // A guest
        $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_PRIVATE);
        self::assertResponseIsSuccessful();

        // The blocked player still sees the blocker - one row, one direction
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);
        $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_REGULAR);
        self::assertResponseIsSuccessful();
    }

    public function testBlockActionIsOfferedOnlyToSignedInVisitorsOfSomeoneElsesProfile(): void
    {
        $browser = self::createClient();

        $block = 'a[href^="/en/action/block_player?"]';

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertCount(0, $crawler->filter($block));

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        // GEST-15 (decision 99): the profile may be restored from Turbo's cache, so it holds no POST form - Block opens its
        // confirmation (the former modal's question and text) in the modal frame
        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertCount(0, $crawler->filter('#blockPlayerModal, form[action*="/block-user/"]'));
        // The ⋯ menu is in the header and in its compact bar
        self::assertCount(2, $crawler->filter($block));
        $crawler->filter($block)->each(static function ($link): void {
            self::assertSame('modal-frame', $link->attr('data-turbo-frame'));
            self::assertStringContainsString('playerId=' . PlayerFixture::PLAYER_WITH_STRIPE, (string) $link->attr('href'));
        });

        $crawler = $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_ADMIN);
        self::assertCount(0, $crawler->filter($block));
    }

    public function testBlockingFromAProfileLandsOnOwnProfileAndHidesThePlayer(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $profileUrl = '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE;
        $crawler = $browser->request('GET', $profileUrl);
        $confirmation = $browser->request('GET', (string) $crawler->filter('a[href^="/en/action/block_player?"]')->first()->attr('href'), server: ['HTTP_TURBO_FRAME' => 'modal-frame']);
        $form = $confirmation->filter('turbo-frame#modal-frame form.post-action-form')->form();

        $browser->submit($form, [], ['HTTP_REFERER' => 'http://localhost' . $profileUrl]);

        self::assertResponseRedirects('/en/my-profile');
        self::assertTrue(
            self::getContainer()->get(GetUserBlocks::class)->isBlocked(PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_WITH_STRIPE),
        );

        $browser->request('GET', $profileUrl);
        self::assertResponseStatusCodeSame(404);
    }

    public function testBlockingNeedsAValidCsrfToken(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('POST', '/en/block-user/' . PlayerFixture::PLAYER_WITH_STRIPE, ['_token' => 'nope']);

        self::assertResponseStatusCodeSame(403);
        self::assertFalse(
            self::getContainer()->get(GetUserBlocks::class)->isBlocked(PlayerFixture::PLAYER_ADMIN, PlayerFixture::PLAYER_WITH_STRIPE),
        );
    }

    public function testEditProfileListsOwnBlocksAndUnblocks(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/edit-profile');
        self::assertResponseIsSuccessful();

        $section = $crawler->filter('#blocked-players');
        self::assertCount(1, $section->filter('.list-group-item'));
        self::assertStringContainsString('Jane Smith', $section->text());

        $browser->submit($section->filter('.list-group-item form')->form());
        self::assertResponseRedirects();

        self::assertFalse(
            self::getContainer()->get(GetUserBlocks::class)->isBlocked(PlayerFixture::PLAYER_REGULAR, PlayerFixture::PLAYER_PRIVATE),
        );

        $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_PRIVATE);
        self::assertResponseIsSuccessful();
    }

    public function testAdminImposedBlockHidesThePlayerButIsNeverListed(): void
    {
        $browser = self::createClient();

        self::getContainer()->get(Connection::class)->executeStatement(
            "INSERT INTO user_block (id, blocker_id, blocked_id, blocked_at, source, note) VALUES (:id, :blocker, :blocked, NOW(), 'admin', 'test')",
            [
                'id' => Uuid::uuid7()->toString(),
                'blocker' => PlayerFixture::PLAYER_WITH_FAVORITES,
                'blocked' => PlayerFixture::PLAYER_WITH_STRIPE,
            ],
        );

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $browser->request('GET', '/en/player-profile/' . PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertResponseStatusCodeSame(404);

        $crawler = $browser->request('GET', '/en/edit-profile');
        self::assertCount(0, $crawler->filter('#blocked-players .list-group-item'));

        $crawler = $browser->request('GET', '/en/blocked-users');
        self::assertCount(0, $crawler->filter('.list-group-item'));
    }
}
