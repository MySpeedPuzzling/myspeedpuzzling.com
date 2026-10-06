<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * An organiser in Wisconsin picks America/Chicago and types the local start - the round must keep
 * both: the edit form shows the same zone and the same time, saving it again moves nothing,
 * and the stored instant is the real start.
 */
final class CompetitionRoundTimezoneTest extends WebTestCase
{
    public function testMultiDayEventRoundRoundTripsInChosenTimezone(): void
    {
        $browser = self::createClient();
        $this->moveEventToUnitedStates(singleDay: false);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/add-event-round/' . CompetitionFixture::COMPETITION_UNAPPROVED);
        $this->assertResponseIsSuccessful();

        $browser->submitForm('Add Round', [
            'competition_round_form[name]' => 'Chicago Solo',
            'competition_round_form[minutesLimit]' => '90',
            'competition_round_form[startsAt]' => '24.10.2026 10:05',
            'competition_round_form[timezone]' => 'America/Chicago',
        ]);
        $this->assertResponseRedirects();

        $round = $this->roundNamed('Chicago Solo');
        // 10:05 in Chicago (CDT, UTC-5) is 15:05 UTC
        self::assertSame('2026-10-24T15:05:00+00:00', $this->utc($round->startsAt));
        $roundId = $round->id->toString();

        $this->assertEditFormShows($browser, $roundId, 'competition_round_form[startsAt]', '24.10.2026 10:05');

        // Saving the untouched form must not move the round
        $browser->submitForm('Save Changes');
        $this->assertResponseRedirects();
        self::assertSame('2026-10-24T15:05:00+00:00', $this->utc($this->roundNamed('Chicago Solo')->startsAt));

        $this->assertEditFormShows($browser, $roundId, 'competition_round_form[startsAt]', '24.10.2026 10:05');
    }

    public function testSingleDayEventRoundRoundTripsInChosenTimezone(): void
    {
        $browser = self::createClient();
        $eventDate = $this->moveEventToUnitedStates(singleDay: true);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/add-event-round/' . CompetitionFixture::COMPETITION_UNAPPROVED);
        $this->assertResponseIsSuccessful();

        $browser->submitForm('Add Round', [
            'competition_round_form[name]' => 'Team Relay',
            'competition_round_form[minutesLimit]' => '120',
            'competition_round_form[startsAtTime]' => '08:05',
            'competition_round_form[timezone]' => 'America/Chicago',
        ]);
        $this->assertResponseRedirects();

        $expected = (new DateTimeImmutable($eventDate . ' 08:05', new DateTimeZone('America/Chicago')))
            ->setTimezone(new DateTimeZone('UTC'))
            ->format('c');

        $round = $this->roundNamed('Team Relay');
        self::assertSame($expected, $this->utc($round->startsAt));
        $roundId = $round->id->toString();

        $this->assertEditFormShows($browser, $roundId, 'competition_round_form[startsAtTime]', '08:05');

        $browser->submitForm('Save Changes');
        $this->assertResponseRedirects();
        self::assertSame($expected, $this->utc($this->roundNamed('Team Relay')->startsAt));

        $this->assertEditFormShows($browser, $roundId, 'competition_round_form[startsAtTime]', '08:05');
    }

    public function testNewRoundPreselectsTheTimezoneOfTheEventsOtherRounds(): void
    {
        $browser = self::createClient();
        $this->moveEventToUnitedStates(singleDay: false);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/add-event-round/' . CompetitionFixture::COMPETITION_UNAPPROVED);
        // No round yet: the country's default zone
        self::assertSame('America/New_York', $this->selectedTimezone($crawler));

        $browser->submitForm('Add Round', [
            'competition_round_form[name]' => 'Chicago Solo',
            'competition_round_form[minutesLimit]' => '90',
            'competition_round_form[startsAt]' => '24.10.2026 10:05',
            'competition_round_form[timezone]' => 'America/Chicago',
        ]);
        $this->assertResponseRedirects();

        $crawler = $browser->request('GET', '/en/add-event-round/' . CompetitionFixture::COMPETITION_UNAPPROVED);
        self::assertSame('America/Chicago', $this->selectedTimezone($crawler));
    }

    public function testRoundTimeIsShownInTheRoundsTimezone(): void
    {
        $browser = self::createClient();
        $this->moveEventToUnitedStates(singleDay: false);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/add-event-round/' . CompetitionFixture::COMPETITION_UNAPPROVED);
        $browser->submitForm('Add Round', [
            'competition_round_form[name]' => 'Chicago Solo',
            'competition_round_form[minutesLimit]' => '90',
            'competition_round_form[startsAt]' => '24.10.2026 10:05',
            'competition_round_form[timezone]' => 'America/Chicago',
        ]);
        $this->assertResponseRedirects();

        $crawler = $browser->followRedirect();
        $this->assertResponseIsSuccessful();
        self::assertStringContainsString('24.10.2026 10:05', $crawler->text());
    }

    private function assertEditFormShows(KernelBrowser $browser, string $roundId, string $timeField, string $expectedTime): void
    {
        $crawler = $browser->request('GET', '/en/edit-event-round/' . $roundId);
        $this->assertResponseIsSuccessful();

        self::assertSame('America/Chicago', $this->selectedTimezone($crawler));
        self::assertSame($expectedTime, $crawler->filter('input[name="' . $timeField . '"]')->attr('value'));
    }

    private function selectedTimezone(\Symfony\Component\DomCrawler\Crawler $crawler): null|string
    {
        return $crawler->filter('select[name="competition_round_form[timezone]"] option[selected]')->attr('value');
    }

    /**
     * @return string The event's (single) day, Y-m-d
     */
    private function moveEventToUnitedStates(bool $singleDay): string
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $competition = $entityManager->find(Competition::class, CompetitionFixture::COMPETITION_UNAPPROVED);
        self::assertNotNull($competition);
        self::assertNotNull($competition->dateFrom);

        $competition->locationCountryCode = 'us';
        $competition->location = 'Madison, WI';

        if ($singleDay) {
            // The event form stores a day as its midnight
            $competition->dateFrom = new DateTimeImmutable($competition->dateFrom->format('Y-m-d'));
            $competition->dateTo = $competition->dateFrom;
        }

        $entityManager->flush();
        $entityManager->clear();

        return $competition->dateFrom->format('Y-m-d');
    }

    private function roundNamed(string $name): CompetitionRound
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();

        $round = $entityManager->getRepository(CompetitionRound::class)->findOneBy([
            'name' => $name,
            'competition' => CompetitionFixture::COMPETITION_UNAPPROVED,
        ]);
        self::assertNotNull($round);

        return $round;
    }

    private function utc(DateTimeImmutable $instant): string
    {
        return $instant->setTimezone(new DateTimeZone('UTC'))->format('c');
    }
}
