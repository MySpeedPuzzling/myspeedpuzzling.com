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

    public function testPastedListAddsOneTeamPerLine(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/manage-round-teams/' . CompetitionSeriesFixture::ROUND_OFFLINE_TEAM);
        $form = $crawler->filter('form[name="add_competition_teams_form"]')->form([
            'add_competition_teams_form[teamNames]' => "Relay Rebels\nPiece of Cake\n",
        ]);
        $browser->submit($form);

        $this->assertResponseRedirects('/en/manage-round-teams/' . CompetitionSeriesFixture::ROUND_OFFLINE_TEAM);

        $browser->followRedirect();
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('.alert-success', '2 teams added.');
        $this->assertAnySelectorTextContains('h3', 'Relay Rebels');
        $this->assertAnySelectorTextContains('h3', 'Piece of Cake');
    }

    public function testListPastedOnOneLineIsRefusedWithAMessage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/manage-round-teams/' . CompetitionSeriesFixture::ROUND_OFFLINE_TEAM);
        $form = $crawler->filter('form[name="add_competition_teams_form"]')->form([
            'add_competition_teams_form[teamNames]' => str_repeat('Some Assembly Required ', 12),
        ]);
        $crawler = $browser->submit($form);

        $this->assertResponseStatusCodeSame(422);
        $this->assertSelectorTextContains('.invalid-feedback', 'is too long for one team name');
        $this->assertSelectorTextContains('.invalid-feedback', 'Put each team on its own line.');
        self::assertCount(0, $crawler->filter('h3'), 'No team may be added.');
    }
}
