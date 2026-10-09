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
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

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
