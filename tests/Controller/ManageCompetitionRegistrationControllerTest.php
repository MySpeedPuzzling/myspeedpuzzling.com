<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Message\ChangeCompetitionRegistrationSettings;
use SpeedPuzzling\Web\Message\JoinCompetition;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The organiser's registration settings and the check-in page (docs/features/competitions-management/registration.md).
 */
final class ManageCompetitionRegistrationControllerTest extends WebTestCase
{
    private const string NATIONALS = CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024;
    private const string SETTINGS = '/en/manage-event-registration/' . self::NATIONALS;
    private const string CHECK_IN = '/en/event-check-in/' . self::NATIONALS;

    public function testOnlyTheEventsMaintainersOpenTheSettings(): void
    {
        $browser = self::createClient();
        // PLAYER_REGULAR maintains other events, not Czech Nationals
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::SETTINGS);

        self::assertResponseStatusCodeSame(403);
    }

    public function testWindowIsTypedInTheChosenZoneAndSavedAsTheInstant(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);
        $this->messageBus()->dispatch(new JoinCompetition(self::NATIONALS, PlayerFixture::PLAYER_REGULAR));

        $crawler = $browser->request('GET', self::SETTINGS);
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
        // One "I'm going" already - it will hold a spot
        self::assertSelectorExists('[data-registration-going-count="1"]');

        $browser->submit($crawler->selectButton('Save registration settings')->form([
            'competition_registration_form[registrationManaged]' => '1',
            'competition_registration_form[capacity]' => '30',
            'competition_registration_form[opensAt]' => '01.03.2027 18:00',
            'competition_registration_form[closesAt]' => '01.04.2027 20:00',
            'competition_registration_form[timezone]' => 'America/Chicago',
            'competition_registration_form[entryFeeText]' => '  $15 per team  ',
            'competition_registration_form[paymentInstructions]' => '',
        ]));

        self::assertResponseRedirects(self::SETTINGS);

        /** @var array{registration_managed: bool, capacity: int, registration_opens_at: string, registration_closes_at: string, registration_timezone: string, entry_fee_text: string, payment_instructions: null|string} $row */
        $row = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT registration_managed, capacity, registration_opens_at, registration_closes_at, registration_timezone, entry_fee_text, payment_instructions FROM competition WHERE id = :id',
            ['id' => self::NATIONALS],
        );
        self::assertTrue($row['registration_managed']);
        self::assertSame(30, $row['capacity']);
        // Chicago is UTC-6 on 1 March and UTC-5 on 1 April (daylight-saving time from 14 March 2027)
        self::assertSame('2027-03-02 00:00:00', $row['registration_opens_at']);
        self::assertSame('2027-04-02 01:00:00', $row['registration_closes_at']);
        self::assertSame('America/Chicago', $row['registration_timezone']);
        self::assertSame('$15 per team', $row['entry_fee_text']);
        self::assertNull($row['payment_instructions']);

        // The form shows the window as it was typed
        $crawler = $browser->request('GET', self::SETTINGS);
        self::assertSame('01.03.2027 18:00', $crawler->filter('#competition_registration_form_opensAt')->attr('value'));
    }

    public function testWindowClosingBeforeItOpensIsRefusedWith422(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', self::SETTINGS);
        $browser->submit($crawler->selectButton('Save registration settings')->form([
            'competition_registration_form[registrationManaged]' => '1',
            'competition_registration_form[opensAt]' => '01.03.2027 18:00',
            'competition_registration_form[closesAt]' => '01.02.2027 18:00',
            'competition_registration_form[timezone]' => 'Europe/Prague',
        ]));

        self::assertResponseStatusCodeSame(422);
        self::assertFalse((bool) self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT registration_managed FROM competition WHERE id = :id',
            ['id' => self::NATIONALS],
        ));
    }

    public function testTooLongEntryFeeIsRefusedWith422(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', self::SETTINGS);
        $browser->submit($crawler->selectButton('Save registration settings')->form([
            'competition_registration_form[registrationManaged]' => '1',
            'competition_registration_form[timezone]' => 'Europe/Prague',
            'competition_registration_form[entryFeeText]' => str_repeat('x', ChangeCompetitionRegistrationSettings::ENTRY_FEE_MAX_LENGTH + 1),
        ]));

        self::assertResponseStatusCodeSame(422);
    }

    public function testCheckInExistsOnlyForAnEventThatManagesRegistration(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', self::CHECK_IN);
        self::assertResponseStatusCodeSame(404);

        $this->manage();
        $browser->request('GET', self::CHECK_IN);

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
    }

    public function testCheckInIsForTheEventsMaintainersOnly(): void
    {
        $browser = self::createClient();
        $this->manage();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', self::CHECK_IN);

        self::assertResponseStatusCodeSame(403);
    }

    private function manage(): void
    {
        $this->messageBus()->dispatch(new ChangeCompetitionRegistrationSettings(
            competitionId: self::NATIONALS,
            registrationManaged: true,
            capacity: 10,
            registrationOpensAt: null,
            registrationClosesAt: null,
            timezone: 'Europe/Prague',
            entryFeeText: null,
            paymentInstructions: null,
        ));
    }

    private function messageBus(): MessageBusInterface
    {
        return self::getContainer()->get(MessageBusInterface::class);
    }
}
