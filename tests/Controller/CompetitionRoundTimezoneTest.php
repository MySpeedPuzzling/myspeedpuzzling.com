<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\ORM\EntityManagerInterface;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\AddPuzzleToCompetitionRound;
use SpeedPuzzling\Web\Tests\DataFixtures\ManufacturerFixture;
use SpeedPuzzling\Web\Value\BrandCodeList;
use SpeedPuzzling\Web\Value\EanList;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use Symfony\Component\Messenger\MessageBusInterface;
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

    public function testRoundOnAnotherLocalDayOfASingleDayEventKeepsItsDate(): void
    {
        $browser = self::createClient();
        $eventDate = $this->moveEventToUnitedStates(singleDay: true);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // An evening round the day after the event's day (in Chicago) - like an online edition across zones
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $competition = $entityManager->find(Competition::class, CompetitionFixture::COMPETITION_UNAPPROVED);
        self::assertNotNull($competition);
        $startsAt = new DateTimeImmutable($eventDate . ' 19:30', new DateTimeZone('America/Chicago'))->modify('+1 day');
        $round = new CompetitionRound(
            id: Uuid::uuid7(),
            competition: $competition,
            name: 'Next Day Round',
            minutesLimit: 60,
            startsAt: $startsAt->setTimezone(new DateTimeZone('UTC')),
            timezone: 'America/Chicago',
        );
        $entityManager->persist($round);
        $entityManager->flush();

        $crawler = $browser->request('GET', '/en/edit-event-round/' . $round->id->toString());
        $this->assertResponseIsSuccessful();
        // The full date and time, not the event day's time-only field - an untouched save must not move it a day
        self::assertCount(0, $crawler->filter('input[name="competition_round_form[startsAtTime]"]'));
        self::assertSame($startsAt->format('d.m.Y H:i'), $crawler->filter('input[name="competition_round_form[startsAt]"]')->attr('value'));

        $browser->submitForm('Save Changes');
        $this->assertResponseRedirects();
        self::assertSame($this->utc($startsAt), $this->utc($this->roundNamed('Next Day Round')->startsAt));
    }

    public function testTimeSkippedOrRepeatedByADaylightSavingChangeIsRefused(): void
    {
        $browser = self::createClient();
        $this->moveEventToUnitedStates(singleDay: false);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        // 2:30 on 8 March 2026 does not exist in Chicago, 1:30 on 1 November 2026 happens twice, 31 February never
        foreach (['08.03.2026 02:30', '01.11.2026 01:30', '31.02.2026 25:70'] as $impossible) {
            $browser->request('GET', '/en/add-event-round/' . CompetitionFixture::COMPETITION_UNAPPROVED);
            $browser->submitForm('Add Round', [
                'competition_round_form[name]' => 'Impossible ' . $impossible,
                'competition_round_form[minutesLimit]' => '60',
                'competition_round_form[startsAt]' => $impossible,
                'competition_round_form[timezone]' => 'America/Chicago',
            ]);
            $this->assertResponseStatusCodeSame(422, $impossible);
        }

        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        self::assertNull($entityManager->getRepository(CompetitionRound::class)->findOneBy(['name' => 'Impossible 08.03.2026 02:30']));
    }

    public function testMovingTheRoundIntoThePastNeedsAnExplicitYesToRevealItsSecretPuzzles(): void
    {
        $browser = self::createClient();
        $this->moveEventToUnitedStates(singleDay: false);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/add-event-round/' . CompetitionFixture::COMPETITION_UNAPPROVED);
        $browser->submitForm('Add Round', [
            'competition_round_form[name]' => 'Secret Round',
            'competition_round_form[minutesLimit]' => '90',
            'competition_round_form[startsAt]' => '24.10.2030 10:05',
            'competition_round_form[timezone]' => 'America/Chicago',
        ]);
        $roundId = $this->roundNamed('Secret Round')->id->toString();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new AddPuzzleToCompetitionRound(
            roundPuzzleId: Uuid::uuid7(),
            roundId: $roundId,
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            brand: ManufacturerFixture::MANUFACTURER_RAVENSBURGER,
            puzzle: 'Tropical Vibes Secret',
            piecesCount: 500,
            puzzlePhoto: null,
            eans: EanList::fromStored(null),
            brandCodes: BrandCodeList::fromStored(null),
            hideUntilRoundStarts: true,
            hideMode: PuzzleHideMode::Entirely,
        ));

        $yesterday = new DateTimeImmutable('-1 day', new DateTimeZone('America/Chicago'))->format('d.m.Y H:i');

        $browser->request('GET', '/en/edit-event-round/' . $roundId);
        $browser->submitForm('Save Changes', ['competition_round_form[startsAt]' => $yesterday]);
        $this->assertResponseStatusCodeSame(422);
        self::assertStringContainsString('Tropical Vibes Secret', (string) $browser->getResponse()->getContent());
        self::assertSame('2030-10-24T15:05:00+00:00', $this->utc($this->roundNamed('Secret Round')->startsAt));

        // A yes for another list than the one shown does not count
        $browser->submitForm('Save Changes', [
            'competition_round_form[startsAt]' => $yesterday,
            'competition_round_form[confirmReveal]' => '1',
            'confirm_reveal_hash' => 'tampered',
        ]);
        $this->assertResponseStatusCodeSame(422);
        self::assertSame('2030-10-24T15:05:00+00:00', $this->utc($this->roundNamed('Secret Round')->startsAt));

        $browser->submitForm('Save Changes', [
            'competition_round_form[startsAt]' => $yesterday,
            'competition_round_form[confirmReveal]' => '1',
        ]);
        $this->assertResponseRedirects();
        self::assertNotSame('2030-10-24T15:05:00+00:00', $this->utc($this->roundNamed('Secret Round')->startsAt));
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
