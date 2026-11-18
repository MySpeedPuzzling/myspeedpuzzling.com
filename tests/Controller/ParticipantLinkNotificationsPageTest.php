<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ClaimParticipant;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture as Wjpc;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\MessageHandler\ParticipantsSheet\SheetChangeSets;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The notifications page renders every participant linking notification (participant-linking.md §9.2) and "Not me"
 * posts to leave_competition (§6.6) - its refusals are pinned in ParticipantLinkNotificationsTest. WJPC 2024 is created by PLAYER_PRIVATE and maintained by PLAYER_WITH_STRIPE here.
 */
final class ParticipantLinkNotificationsPageTest extends WebTestCase
{
    use SheetChangeSets;

    private const string EVENT = CompetitionFixture::COMPETITION_WJPC_2024;
    private const string ORGANISER = PlayerFixture::PLAYER_PRIVATE;

    public function testEveryKindRendersAndNotMeUnlinks(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);
        $messageBus = self::getContainer()->get(MessageBusInterface::class);

        $database->executeStatement("UPDATE competition SET added_by_player_id = :creator, participant_claims = 'approval' WHERE id = :id", ['creator' => self::ORGANISER, 'id' => self::EVENT]);
        $declinedName = $this->newParticipant($database, 'Declined Name');
        $listedName = $this->newParticipant($database, 'Sarah Listed');

        // Approved: PLAYER_ADMIN asks, the organiser approves
        $messageBus->dispatch(new ClaimParticipant(self::EVENT, PlayerFixture::PLAYER_ADMIN, Wjpc::PARTICIPANT_UNCONNECTED));
        $this->applySheetChanges([self::sheetGroup(['op' => 'player', 'participant' => Wjpc::PARTICIPANT_UNCONNECTED, 'from' => null, 'to' => PlayerFixture::PLAYER_ADMIN])], competitionId: self::EVENT, actingPlayerId: self::ORGANISER);

        // Declined: PLAYER_REGULAR asks, the organiser says no
        $messageBus->dispatch(new ClaimParticipant(self::EVENT, PlayerFixture::PLAYER_REGULAR, $declinedName));
        $requestId = $database->fetchOne("SELECT id FROM participant_link_request WHERE participant_id = :id AND status = 'pending'", ['id' => $declinedName]);
        self::assertIsString($requestId);
        $this->applySheetChanges([self::sheetGroup(['op' => 'declineLinkRequest', 'request' => $requestId, 'from' => 'pending'])], competitionId: self::EVENT, actingPlayerId: self::ORGANISER);

        // Listed: the organiser links PLAYER_WITH_STRIPE
        $this->applySheetChanges([self::sheetGroup(['op' => 'player', 'participant' => $listedName, 'from' => null, 'to' => PlayerFixture::PLAYER_WITH_STRIPE])], competitionId: self::EVENT, actingPlayerId: self::ORGANISER);

        // The organiser: "people are waiting" (told once for both requests), to the sheet's "Asks to be linked"
        $organiser = $this->page($browser, self::ORGANISER);
        self::assertCount(1, $organiser->filter('[data-type="ParticipantLinkRequested"]'));
        self::assertStringContainsString('People are waiting for you to confirm their participant link at WJPC 2024', $organiser->filter('[data-type="ParticipantLinkRequested"]')->text());
        self::assertSame(
            '/en/participants-sheet/' . self::EVENT . '?tab=people&filter=asks',
            $organiser->filter('[data-testid="participant-link-notification-review"]')->attr('href'),
        );

        $approved = $this->page($browser, PlayerFixture::PLAYER_ADMIN)->filter('[data-type="ParticipantLinkApproved"]');
        self::assertCount(1, $approved);
        self::assertStringContainsString("You're linked to Jane Unconnected at WJPC 2024", $approved->text());

        $declined = $this->page($browser, PlayerFixture::PLAYER_REGULAR)->filter('[data-type="ParticipantLinkDeclined"]');
        self::assertCount(1, $declined);
        self::assertStringContainsString('The organiser of WJPC 2024 did not confirm Declined Name as you', $declined->text());

        $listedPage = $this->page($browser, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertStringContainsString("You're on the participant list of WJPC 2024 as Sarah Listed", $listedPage->filter('[data-type="ParticipantListedYou"]')->text());
        self::assertCount(1, $listedPage->filter('[data-testid="participant-link-not-me"]'));

        // A wrong token changes nothing
        $browser->request('POST', '/en/leave-event/' . self::EVENT, ['not_me' => '1', 'participant' => $listedName, '_token' => 'wrong']);
        self::assertResponseStatusCodeSame(303);
        self::assertSame(PlayerFixture::PLAYER_WITH_STRIPE, $this->linkedPlayer($database, $listedName));

        // "Not me" from the notification
        $browser->submit($listedPage->filter('[data-testid="participant-link-not-me"]')->form());
        self::assertResponseRedirects('/en/events/wjpc-2024', 303);
        self::assertNull($this->linkedPlayer($database, $listedName));

        $browser->followRedirect();
        self::assertStringContainsString('you are no longer linked to that name', (string) $browser->getResponse()->getContent());

        // An approval offers no "Not me" - the player asked for the link (somebody else's participant: the handler test)
        self::assertCount(0, $this->page($browser, PlayerFixture::PLAYER_ADMIN)->filter('[data-testid="participant-link-not-me"]'));
    }

    private function page(KernelBrowser $browser, string $playerId): Crawler
    {
        TestingLogin::asPlayer($browser, $playerId);
        $crawler = $browser->request('GET', '/en/notifications');
        self::assertResponseIsSuccessful();

        return $crawler;
    }

    private function newParticipant(Connection $database, string $name): string
    {
        $id = Uuid::uuid7()->toString();
        $database->executeStatement(
            "INSERT INTO competition_participant (id, name, country, competition_id, source) VALUES (:id, :name, 'cz', :competition, 'imported')",
            ['id' => $id, 'name' => $name, 'competition' => self::EVENT],
        );

        return $id;
    }

    private function linkedPlayer(Connection $database, string $participantId): null|string
    {
        $playerId = $database->fetchOne('SELECT player_id FROM competition_participant WHERE id = :id', ['id' => $participantId]);

        return is_string($playerId) ? $playerId : null;
    }
}
