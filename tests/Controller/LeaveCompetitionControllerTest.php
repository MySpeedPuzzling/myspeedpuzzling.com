<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class LeaveCompetitionControllerTest extends WebTestCase
{
    public function testLeavingAnEditionReturnsToTheEditionPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        // The edition has no participant list - "I'm going" joins at once
        $browser->request('GET', '/en/join-event/' . CompetitionSeriesFixture::EDITION_EJJ_69);
        self::assertSame(1, $this->participantRowsOf(PlayerFixture::PLAYER_ADMIN, CompetitionSeriesFixture::EDITION_EJJ_69));

        $browser->request('POST', '/en/leave-event/' . CompetitionSeriesFixture::EDITION_EJJ_69);

        // Never event_detail with the edition's slug - an edition slug is only unique within its series
        $this->assertResponseRedirects('/en/series/euro-jigsaw-jam-series/ejj-69-may-2026');
        self::assertSame(0, $this->participantRowsOf(PlayerFixture::PLAYER_ADMIN, CompetitionSeriesFixture::EDITION_EJJ_69));
    }

    public function testLeavingAStandaloneEventReturnsToTheEventPage(): void
    {
        $browser = self::createClient();

        // PLAYER_REGULAR is connected to 'John Regular' of WJPC 2024
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('POST', '/en/leave-event/' . CompetitionFixture::COMPETITION_WJPC_2024);

        $this->assertResponseRedirects('/en/events/wjpc-2024');
        self::assertSame(0, $this->participantRowsOf(PlayerFixture::PLAYER_REGULAR, CompetitionFixture::COMPETITION_WJPC_2024));
    }

    public function testLeavingAnUnknownCompetitionIsNotFound(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('POST', '/en/leave-event/018d0000-0000-0000-0000-00000000dead');

        $this->assertResponseStatusCodeSame(404);
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
