<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ManageRoundTeamsControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/manage-round-teams/' . CompetitionSeriesFixture::ROUND_OFFLINE_TEAM);

        $this->assertResponseRedirects();
    }

    public function testMaintainerCanAccessPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/manage-round-teams/' . CompetitionSeriesFixture::ROUND_OFFLINE_TEAM);

        $this->assertResponseIsSuccessful();
    }

    public function testAddTeamFormWorks(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('POST', '/en/add-team-to-round/' . CompetitionSeriesFixture::ROUND_OFFLINE_TEAM, [
            'team_name' => 'Test Team',
        ]);

        $this->assertResponseRedirects();

        $browser->followRedirect();
        $this->assertResponseIsSuccessful();
    }
}
