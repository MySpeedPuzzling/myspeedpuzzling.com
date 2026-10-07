<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The old pairs/teams page of a round is retired for the round's tab of the participants spreadsheet
 * (participants-spreadsheet.md D12): an organiser lands on that tab, everybody else is refused as before.
 */
final class ManageRoundTeamsControllerTest extends WebTestCase
{
    private const string OLD_URL = '/en/manage-round-teams/' . CompetitionSeriesFixture::ROUND_OFFLINE_TEAM;

    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();
        $browser->request('GET', self::OLD_URL);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('participants-sheet', (string) $browser->getResponse()->headers->get('Location'));
    }

    public function testTheOrganiserLandsOnTheRoundsTabOfTheSheet(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', self::OLD_URL);

        self::assertResponseRedirects('/en/participants-sheet/' . CompetitionSeriesFixture::EDITION_OFFLINE_1 . '?tab=' . CompetitionSeriesFixture::ROUND_OFFLINE_TEAM, 302);
        $crawler = $browser->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSame(CompetitionSeriesFixture::ROUND_OFFLINE_TEAM, $crawler->filter('[data-controller="participants-sheet"]')->attr('data-participants-sheet-tab-value'));
    }

    public function testSomebodyWhoDoesNotOrganiseTheEventIsRefused(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::OLD_URL);

        self::assertResponseStatusCodeSame(403);
    }

    public function testAnUnknownRoundIsNotFound(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/manage-round-teams/018d0005-0000-0000-0000-000000009999');

        self::assertResponseStatusCodeSame(404);
    }
}
