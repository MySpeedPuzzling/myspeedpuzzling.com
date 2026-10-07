<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * Euro Jigsaw Jam is an online series without a country, its rounds were saved before rounds kept their zone: their
 * zone is only the fallback (RoundTimezone::isAssumed()) and must not be named after a country ("Czechia Time") -
 * nobody said the event is in Czechia. A zone the organiser picked, or one taken from the event's country, keeps
 * its place name.
 */
final class CompetitionRoundAssumedTimezoneTest extends WebTestCase
{
    public function testRoundListNamesAnAssumedZoneWithoutACountry(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/manage-event-rounds/' . CompetitionSeriesFixture::EDITION_EJJ_69);
        $this->assertResponseIsSuccessful();

        self::assertSame('(Central European Time)', $crawler->filter('[data-round-zone]')->text());
    }

    public function testRoundListNamesTheEventCountrysZoneByItsPlace(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/manage-event-rounds/' . CompetitionFixture::COMPETITION_WJPC_2024);
        $this->assertResponseIsSuccessful();

        self::assertSame(['(Czechia Time)', '(Czechia Time)'], $crawler->filter('[data-round-zone]')->each(static fn ($node): string => $node->text()));
    }

    public function testRoundResultsNameAnAssumedZoneWithoutACountryAndSaySolo(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/series/euro-jigsaw-jam-series/ejj-68-february-2026/results/main-round');
        $this->assertResponseIsSuccessful();

        self::assertSame('(Central European Time)', $crawler->filter('[data-round-zone]')->text());
        self::assertSame('Solo', $crawler->filter('[data-round-category]')->text());
    }

    public function testEditionPageNamesAnAssumedZoneWithoutACountry(): void
    {
        $browser = self::createClient();

        $crawler = $browser->request('GET', '/en/series/euro-jigsaw-jam-series/ejj-68-february-2026');
        $this->assertResponseIsSuccessful();

        self::assertSame('(Central European Time)', $crawler->filter('[data-round-zone]')->first()->text());
    }

    public function testEditFormSaysTheZoneWasNeverSaved(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/edit-event-round/' . CompetitionSeriesFixture::ROUND_EJJ_69);
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('No time zone was saved for this round yet', $crawler->filter('#competition_round_form_timezone_help')->text());

        // Saved with a zone, it is no longer assumed
        $browser->submitForm('Save Changes', ['competition_round_form[timezone]' => 'America/Toronto']);
        $this->assertResponseRedirects();

        $crawler = $browser->request('GET', '/en/edit-event-round/' . CompetitionSeriesFixture::ROUND_EJJ_69);
        self::assertStringContainsString('Times will be displayed in this timezone.', $crawler->filter('#competition_round_form_timezone_help')->text());

        $crawler = $browser->request('GET', '/en/manage-event-rounds/' . CompetitionSeriesFixture::EDITION_EJJ_69);
        self::assertSame('(Toronto Time)', $crawler->filter('[data-round-zone]')->text());
    }
}
