<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\CompetitionParticipant;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionParticipantFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\RateLimiter\RateLimiterFactoryInterface;

final class JoinCompetitionControllerTest extends WebTestCase
{
    private const string UPCOMING_EDITION_URL = '/en/series/euro-jigsaw-jam-series/ejj-69-may-2026';

    public function testPickerOffersOnlyUnclaimedNames(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/join-event/' . CompetitionFixture::COMPETITION_WJPC_2024);

        $this->assertResponseIsSuccessful();

        $offeredNames = $crawler->filter('select[name="participant_id"] option')->each(
            static fn ($option): string => trim($option->text()),
        );

        // Connected ('John Regular'), private ('Secret Player'), self-joined ('Michael Johnson')
        // and soft-deleted ('Deleted Person') participants are not up for grabs
        self::assertSame(['— Select your name —', 'Jane Unconnected'], $offeredNames);
    }

    public function testPlayerWhoseNameIsOnTheListIsConnectedWithoutPicking(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);

        // PLAYER_ADMIN is 'Admin User' from 'cz'
        $database->executeStatement(
            "UPDATE competition_participant SET name = 'Admin  user', country = 'cz' WHERE id = :id",
            ['id' => CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED],
        );

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/join-event/' . CompetitionFixture::COMPETITION_WJPC_2024);

        // An in-person event and a member with offers: on to "What will you bring?" (JoinMarketplaceFollowUpTest)
        $this->assertResponseRedirects('/en/events/' . CompetitionFixture::COMPETITION_WJPC_2024 . '/what-i-bring?joined=1');
        self::assertSame(PlayerFixture::PLAYER_ADMIN, $database->fetchOne(
            'SELECT player_id FROM competition_participant WHERE id = :id',
            ['id' => CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED],
        ));
    }

    public function testSubmittingPickerWithoutNameDoesNotJoin(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('POST', '/en/join-event/' . CompetitionFixture::COMPETITION_WJPC_2024, ['participant_id' => '']);

        $this->assertResponseRedirects('/en/join-event/' . CompetitionFixture::COMPETITION_WJPC_2024);
        self::assertSame(0, $this->participantRowsOf(PlayerFixture::PLAYER_ADMIN, CompetitionFixture::COMPETITION_WJPC_2024));
    }

    public function testJoiningEventWithoutListJoinsDirectly(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/join-event/' . CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024);

        // An in-person event and a member with offers: on to "What will you bring?" (JoinMarketplaceFollowUpTest)
        $this->assertResponseRedirects('/en/events/' . CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024 . '/what-i-bring?joined=1');
        self::assertSame(1, $this->participantRowsOf(PlayerFixture::PLAYER_ADMIN, CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024));
    }

    public function testJoiningAnEditionReturnsToTheEditionPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/join-event/' . CompetitionSeriesFixture::EDITION_EJJ_69);

        // Never event_detail with the edition's slug - an edition slug is only unique within its series
        $this->assertResponseRedirects(self::UPCOMING_EDITION_URL);
        self::assertSame(1, $this->participantRowsOf(PlayerFixture::PLAYER_ADMIN, CompetitionSeriesFixture::EDITION_EJJ_69));
    }

    public function testPickerOfAnEditionLinksBackToTheEditionPage(): void
    {
        $browser = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new CompetitionParticipant(
            id: Uuid::uuid7(),
            name: 'Listed Edition Puzzler',
            country: 'de',
            competition: self::getContainer()->get(CompetitionRepository::class)->get(CompetitionSeriesFixture::EDITION_EJJ_69),
        ));
        $entityManager->flush();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/join-event/' . CompetitionSeriesFixture::EDITION_EJJ_69);

        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('select[name="participant_id"]');
        // The breadcrumb and the back button
        $this->assertSelectorCount(2, 'a[href="' . self::UPCOMING_EDITION_URL . '"]');
        $this->assertSelectorNotExists('a[href="/en/events/ejj-69-may-2026"]');
    }

    /**
     * The join page lists the organiser's names - only for a publicly visible event (docs/features/organizations/README.md,
     * P17): one waiting for approval, an edition of a series waiting for it and a draft answer 404, GET and POST alike
     */
    public function testAnEventThatIsNotPublicHasNoJoinPage(): void
    {
        $browser = self::createClient();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->persist(new CompetitionParticipant(
            id: Uuid::uuid7(),
            name: 'Listed Pending Puzzler',
            country: 'de',
            competition: self::getContainer()->get(CompetitionRepository::class)->get(CompetitionFixture::COMPETITION_UNAPPROVED),
        ));
        $entityManager->flush();

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        foreach ([CompetitionFixture::COMPETITION_UNAPPROVED, CompetitionSeriesFixture::EDITION_UNAPPROVED_1, OrganizationFixture::COMPETITION_DRAFT_NIGHT] as $competitionId) {
            $browser->request('GET', '/en/join-event/' . $competitionId);
            $this->assertResponseStatusCodeSame(404);

            $browser->request('POST', '/en/join-event/' . $competitionId, ['self_join' => '1']);
            $this->assertResponseStatusCodeSame(404);
            self::assertSame(0, $this->participantRowsOf(PlayerFixture::PLAYER_ADMIN, $competitionId));
        }
    }

    /**
     * R11 (participant-linking.md §0a): where the organiser confirms who is who, the GET never links the name match - it
     * is pre-selected and the page says a pick waits for the organiser (§6.2)
     */
    public function testUnderApprovalTheGetOnlyPreselectsTheMatch(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement("UPDATE competition SET participant_claims = 'approval' WHERE id = :id", ['id' => CompetitionFixture::COMPETITION_WJPC_2024]);
        $database->executeStatement(
            "UPDATE competition_participant SET name = 'Admin  user', country = 'cz' WHERE id = :id",
            ['id' => CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED],
        );
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/join-event/' . CompetitionFixture::COMPETITION_WJPC_2024);

        $this->assertResponseIsSuccessful();
        self::assertNull($database->fetchOne('SELECT player_id FROM competition_participant WHERE id = :id', ['id' => CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED]));
        self::assertSame(CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED, $crawler->filter('select[name="participant_id"] option[selected]')->attr('value'));
        self::assertCount(1, $crawler->filter('[data-claims-need-approval]'));
        self::assertStringContainsString('Ask the organiser to link me', $crawler->filter('[data-claim-submit="ask"]')->text());
        self::assertStringContainsString('The organisers will see your name and #code', $crawler->text());

        // The pick is a request: not going yet, the event page says it waits - and Take back withdraws it
        $browser->request('POST', '/en/join-event/' . CompetitionFixture::COMPETITION_WJPC_2024, ['participant_id' => CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED]);
        $this->assertResponseRedirects();
        $page = $browser->followRedirect();
        self::assertSelectorTextContains('.alert-success', 'Sent - the organiser will confirm it.');
        self::assertSame(0, $this->participantRowsOf(PlayerFixture::PLAYER_ADMIN, CompetitionFixture::COMPETITION_WJPC_2024));
        $pending = $page->filter('[data-participant-link-pending]');
        self::assertCount(1, $pending);
        self::assertStringContainsString('Waiting for the organiser to confirm you are Admin user', $pending->text());

        $browser->submit($page->filter('[data-participant-link-withdraw]')->form());
        $this->assertResponseRedirects();
        $page = $browser->followRedirect();
        self::assertCount(0, $page->filter('[data-participant-link-pending]'));
        self::assertSame('withdrawn', $database->fetchOne('SELECT status FROM participant_link_request WHERE player_id = :pid', ['pid' => PlayerFixture::PLAYER_ADMIN]));
    }

    /**
     * A name match the organiser refused for this player is no match: the open GET shows the list instead of a claim
     * that would only say "ask the organiser" on every visit - the player can still join as themselves
     */
    public function testARefusedNameMatchLeavesThePageToThePlayer(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement(
            "UPDATE competition_participant SET name = 'Admin  user', country = 'cz' WHERE id = :id",
            ['id' => CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED],
        );
        $this->refusal($database, CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED, PlayerFixture::PLAYER_ADMIN);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/join-event/' . CompetitionFixture::COMPETITION_WJPC_2024);

        $this->assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('select[name="participant_id"] option[selected]'));
        self::assertCount(1, $crawler->filter('input[name="self_join"]'));
        self::assertNull($database->fetchOne('SELECT player_id FROM competition_participant WHERE id = :id', ['id' => CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED]));
    }

    /**
     * A player whose claims ended at the event (3 refusals): the GET's name match asks the organiser once, on the page
     * with the list - never a redirect away from it
     */
    public function testAPlayerWithThreeRefusalsStillGetsThePage(): void
    {
        $browser = self::createClient();
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement(
            "UPDATE competition_participant SET name = 'Admin  user', country = 'cz' WHERE id = :id",
            ['id' => CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED],
        );

        foreach ([CompetitionParticipantFixture::PARTICIPANT_CONNECTED, CompetitionParticipantFixture::PARTICIPANT_PRIVATE, CompetitionParticipantFixture::PARTICIPANT_SELF_JOINED] as $refused) {
            $this->refusal($database, $refused, PlayerFixture::PLAYER_ADMIN);
        }

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/join-event/' . CompetitionFixture::COMPETITION_WJPC_2024);

        $this->assertResponseIsSuccessful();
        self::assertSelectorTextContains('.alert-warning', 'Please ask the organiser to link you.');
        self::assertCount(1, $crawler->filter('input[name="self_join"]'));
        self::assertNull($database->fetchOne('SELECT player_id FROM competition_participant WHERE id = :id', ['id' => CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED]));
    }

    public function testAPickOfANameLinkedToSomebodyElseAsksTheOrganiser(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('POST', '/en/join-event/' . CompetitionFixture::COMPETITION_WJPC_2024, ['participant_id' => CompetitionParticipantFixture::PARTICIPANT_CONNECTED]);

        $this->assertResponseRedirects();
        $browser->followRedirect();
        self::assertSelectorTextContains('.alert-info', 'This name is linked to another profile. We asked the organiser to check it.');
        self::assertSame(0, $this->participantRowsOf(PlayerFixture::PLAYER_ADMIN, CompetitionFixture::COMPETITION_WJPC_2024));
    }

    /**
     * Limiter participant_claim (participant-linking.md §10.2): per player, the picks stop after the budget
     */
    public function testTooManyPicksAreStopped(): void
    {
        // PLAYER_PRIVATE picks a name in no other test - the limiter's budget (shared by paratest workers) is theirs only
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_PRIVATE);
        /** @var RateLimiterFactoryInterface $limiter */
        $limiter = self::getContainer()->get('limiter.participant_claim');
        $limiter->create(PlayerFixture::PLAYER_PRIVATE)->reset();
        $limiter->create(PlayerFixture::PLAYER_PRIVATE)->consume(100000);

        try {
            $browser->request('POST', '/en/join-event/' . CompetitionFixture::COMPETITION_WJPC_2024, ['participant_id' => CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED]);

            $this->assertResponseRedirects('/en/join-event/' . CompetitionFixture::COMPETITION_WJPC_2024);
            self::assertNull(self::getContainer()->get(Connection::class)->fetchOne('SELECT player_id FROM competition_participant WHERE id = :id', ['id' => CompetitionParticipantFixture::PARTICIPANT_UNCONNECTED]));
            $browser->followRedirect();
            self::assertSelectorTextContains('.alert-warning', 'Too many tries');
        } finally {
            $limiter->create(PlayerFixture::PLAYER_PRIVATE)->reset();
        }
    }

    private function refusal(Connection $database, string $participantId, string $playerId): void
    {
        $database->executeStatement(
            "INSERT INTO participant_link_log (id, competition_id, participant_id, participant_name, player_id, player_name, action, method, by_player_id, at)
             VALUES (:id, :competition, :participant, 'x', :player, 'y', 'not_this_person', NULL, NULL, " . TestClock::SQL_NOW . ")",
            ['id' => Uuid::uuid7()->toString(), 'competition' => CompetitionFixture::COMPETITION_WJPC_2024, 'participant' => $participantId, 'player' => $playerId],
        );
    }

    private function participantRowsOf(string $playerId, string $competitionId): int
    {
        $database = self::getContainer()->get(Connection::class);

        $count = $database->fetchOne(
            'SELECT count(*) FROM competition_participant WHERE competition_id = :cid AND player_id = :pid AND deleted_at IS NULL',
            ['cid' => $competitionId, 'pid' => $playerId],
        );
        assert(is_int($count));

        return $count;
    }
}
