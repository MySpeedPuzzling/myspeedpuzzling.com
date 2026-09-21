<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The whole journey of "this guest has an account now": asked on the Guests tab, answered by the
 * other player from their notifications.
 */
final class GuestLinkControllerTest extends WebTestCase
{
    public function testGuestIsLinkedOnceTheOtherPlayerConfirms(): void
    {
        $browser = self::createClient();
        $this->addTimeWithGuest('Michael');
        $this->addTimeWithGuest('Michael');

        // 1. The asker names the player on the Guests tab
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', '/en/pairs-and-teams?show=guests');
        $form = $crawler->filter('.pairs-and-teams-guest[data-guest-key="g:michael"] form[action$="/guests/link"]')->form(['code' => '#player3']);
        $browser->submit($form);

        $this->assertResponseRedirects('/en/pairs-and-teams?show=guests');
        $crawler = $browser->followRedirect();
        $this->assertSelectorTextContains('body', 'Nothing changes until they confirm');
        self::assertStringContainsString('#PLAYER3', $crawler->filter('[data-testid="guest-link-pending"]')->text());

        // 2. The asked player finds it among their notifications…
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $crawler = $browser->request('GET', '/en/notifications');
        $this->assertSelectorTextContains('body', PlayerFixture::PLAYER_WITH_STRIPE_NAME . ' says you are “Michael”');

        // 3. …opens the question: who asks, about which results - looking changes nothing
        $crawler = $browser->click($crawler->filter('a[href*="/pairs-and-teams/guest-link/"]')->link());
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Is this you?');
        self::assertCount(2, $crawler->filter('[data-testid="guest-link-results"] tbody tr'));
        self::assertSame(0, $this->number("SELECT COUNT(*) FROM puzzling_team_member WHERE player_id = '" . PlayerFixture::PLAYER_WITH_FAVORITES . "'"));

        // 4. …and confirms
        $browser->submit($crawler->selectButton('Yes, that is me')->form());
        $this->assertResponseRedirects('/en/pairs-and-teams');
        $crawler = $browser->followRedirect();

        // The pair is theirs now, with both results
        $card = $crawler->filter('.pairs-and-teams-card');
        self::assertCount(1, $card);
        self::assertStringContainsString(PlayerFixture::PLAYER_WITH_STRIPE_NAME, $card->text());
        self::assertStringContainsString('2× together', $card->text());

        // Answered questions cannot be answered again
        $requestId = $this->value('SELECT id FROM guest_link_request');
        $crawler = $browser->request('GET', '/en/pairs-and-teams/guest-link/' . $requestId);
        self::assertCount(1, $crawler->filter('[data-testid="guest-link-answered"]'));
        self::assertCount(0, $crawler->selectButton('Yes, that is me'));

        // 5. The asker hears about it, and the guest is gone from their Guests tab
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $browser->request('GET', '/en/notifications');
        $this->assertSelectorTextContains('body', 'confirmed they are “Michael”');

        $crawler = $browser->request('GET', '/en/pairs-and-teams?show=guests');
        self::assertCount(0, $crawler->filter('.pairs-and-teams-guest[data-guest-key="g:michael"]'));
    }

    public function testQuestionIsNobodyElsesBusiness(): void
    {
        $browser = self::createClient();
        $this->addTimeWithGuest('Michael');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', '/en/pairs-and-teams?show=guests');
        $browser->submit($crawler->filter('form[action$="/guests/link"]')->form(['code' => 'player3']));
        $requestId = $this->value('SELECT id FROM guest_link_request');

        // Not even whoever asked can open - or answer - it
        $browser->request('GET', '/en/pairs-and-teams/guest-link/' . $requestId);
        $this->assertResponseStatusCodeSame(404);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $browser->request('GET', '/en/pairs-and-teams/guest-link/' . $requestId);
        $this->assertResponseStatusCodeSame(404);
    }

    public function testUnknownCodeIsSaidSo(): void
    {
        $browser = self::createClient();
        $this->addTimeWithGuest('Michael');

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $browser->request('GET', '/en/pairs-and-teams?show=guests');
        $browser->submit($crawler->filter('form[action$="/guests/link"]')->form(['code' => '#nobody-has-this']));

        $this->assertResponseRedirects('/en/pairs-and-teams?show=guests');
        $browser->followRedirect();
        $this->assertSelectorTextContains('body', 'No player has that code');
    }

    private function addTimeWithGuest(string $guest): void
    {
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleSolvingTime(
            timeId: Uuid::uuid7(),
            userId: PlayerFixture::PLAYER_WITH_STRIPE_USER_ID,
            puzzleId: PuzzleFixture::PUZZLE_1500_01,
            competitionId: null,
            time: '05:00:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [$guest],
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));
    }

    private function number(string $sql): int
    {
        /** @var int $count */
        $count = self::getContainer()->get(Connection::class)->fetchOne($sql);

        return $count;
    }

    private function value(string $sql): string
    {
        $value = self::getContainer()->get(Connection::class)->fetchOne($sql);
        self::assertIsString($value);

        return $value;
    }
}
