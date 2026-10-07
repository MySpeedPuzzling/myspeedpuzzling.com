<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Tests\UploadsCompetitionLogos;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AddCompetitionControllerTest extends WebTestCase
{
    use UploadsCompetitionLogos;

    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/add-event');

        $this->assertResponseRedirects();
    }

    public function testLoggedInUserCanAccessPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/add-event');

        $this->assertResponseIsSuccessful();
    }

    public function testSubmitWithOnlyRequiredFields(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/add-event');
        $this->assertResponseIsSuccessful();

        $browser->submitForm('Submit for Approval', [
            'competition_form[name]' => 'Test Puzzle Event',
            'competition_form[isOnline]' => '0',
            'competition_form[location]' => 'Prague',
            'competition_form[dateFrom]' => '15.06.2026',
            'competition_form[dateTo]' => '17.06.2026',
        ]);

        $this->assertResponseRedirects();
        $browser->followRedirect();
        $this->assertResponseIsSuccessful();
    }

    public function testSubmitWithAllFields(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/add-event');
        $this->assertResponseIsSuccessful();

        $browser->submitForm('Submit for Approval', [
            'competition_form[name]' => 'Full Puzzle Championship',
            'competition_form[shortcut]' => 'FPC',
            'competition_form[description]' => 'A test competition with all fields filled.',
            'competition_form[location]' => 'Prague',
            'competition_form[dateFrom]' => '15.06.2026',
            'competition_form[dateTo]' => '17.06.2026',
            'competition_form[link]' => 'https://example.com',
            'competition_form[registrationLink]' => 'https://example.com/register',
            'competition_form[resultsLink]' => 'https://example.com/results',
            'competition_form[isOnline]' => '1',
            'competition_form[isRecurring]' => true,
        ]);

        $this->assertResponseRedirects();
        $browser->followRedirect();
        $this->assertResponseIsSuccessful();
    }

    public function testAnOnlineEventKeepsTheDatesTyped(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/add-event');
        $browser->submitForm('Submit for Approval', [
            'competition_form[name]' => 'Online Weekend Jam',
            'competition_form[isOnline]' => '1',
            'competition_form[location]' => 'Should not be stored',
            'competition_form[dateFrom]' => '14.11.2026',
            'competition_form[dateTo]' => '15.11.2026',
        ]);

        $this->assertResponseRedirects();
        $row = self::competitionRow('Online Weekend Jam');
        self::assertTrue($row['is_online']);
        self::assertNull($row['location']);
        self::assertIsString($row['date_from']);
        self::assertIsString($row['date_to']);
        self::assertStringStartsWith('2026-11-14', $row['date_from']);
        self::assertStringStartsWith('2026-11-15', $row['date_to']);
    }

    public function testAnOnlineEventMayBeUndated(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/add-event');
        $browser->submitForm('Submit for Approval', [
            'competition_form[name]' => 'Ongoing Online Jam',
            'competition_form[isOnline]' => '1',
        ]);

        $this->assertResponseRedirects();
        $row = self::competitionRow('Ongoing Online Jam');
        self::assertNull($row['date_from']);
        self::assertNull($row['date_to']);
    }

    public function testTheFormSaysWhereTheLinksOfASeriesGo(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/add-event');

        // Shown in place of the registration and results fields once "recurring" is ticked
        self::assertCount(1, $crawler->filter('[data-competition-form-target="editionLinks"] #competition_form_registrationLink'));
        self::assertCount(1, $crawler->filter('[data-competition-form-target="editionLinks"] #competition_form_resultsLink'));
        $this->assertSelectorTextContains('[data-competition-form-target="editionLinksNote"]', 'Registration and results links are set for each edition');
        // The add form never offers the URL - the first one comes from the name
        self::assertCount(0, $crawler->filter('#competition_form_slug'));
    }

    public function testWhatWasTypedInFieldsHiddenByRecurringCannotBlockTheSeries(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/add-event');

        // Typed before "recurring" was ticked and hid them - an error there would be invisible
        $browser->submitForm('Submit for Approval', [
            'competition_form[name]' => 'Hidden Fields Series',
            'competition_form[location]' => 'Prague',
            'competition_form[isOnline]' => '0',
            'competition_form[isRecurring]' => true,
            'competition_form[registrationLink]' => 'not a url',
            'competition_form[dateFrom]' => '20.06.2026',
            'competition_form[dateTo]' => '01.06.2026',
        ]);

        $this->assertResponseRedirects();
        self::assertNotFalse(self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT id FROM competition_series WHERE name = :name',
            ['name' => 'Hidden Fields Series'],
        ));
    }

    public function testALogoChosenForARefusedSubmitIsKeptForTheNextOne(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        // The kept logo lives in the kernel's storage - one kernel for both submits
        $browser->disableReboot();

        $crawler = $browser->request('GET', '/en/add-event');
        $form = $crawler->selectButton('Submit for Approval')->form();
        $form['competition_form[isOnline]'] = '0';
        $form['competition_form[location]'] = 'Prague';
        $form['competition_form[dateFrom]'] = '15.06.2026';
        $form['competition_form[dateTo]'] = '17.06.2026';
        self::attachLogo($form);
        // No name - refused
        $crawler = $browser->submit($form);

        $this->assertResponseStatusCodeSame(422);
        self::assertLogoKept($crawler);

        $form = $crawler->selectButton('Submit for Approval')->form();
        $form['competition_form[name]'] = 'Event With A Kept Logo';
        $browser->submit($form);

        $this->assertResponseRedirects();
        $logo = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT logo FROM competition WHERE name = :name',
            ['name' => 'Event With A Kept Logo'],
        );
        self::assertIsString($logo);
        self::assertStringStartsWith('competitions/', $logo);
    }

    public function testALogoChosenForARefusedSeriesSubmitIsKeptForTheNextOne(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->disableReboot();

        $crawler = $browser->request('GET', '/en/add-event');
        $form = $crawler->selectButton('Submit for Approval')->form();
        $form['competition_form[isOnline]'] = '1';
        $form->setValues(['competition_form[isRecurring]' => true]);
        self::attachLogo($form);
        // No name - refused
        $crawler = $browser->submit($form);

        $this->assertResponseStatusCodeSame(422);
        self::assertLogoKept($crawler);

        $form = $crawler->selectButton('Submit for Approval')->form();
        $form['competition_form[name]'] = 'Series With A Kept Logo';
        $browser->submit($form);

        $this->assertResponseRedirects();
        $logo = self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT logo FROM competition_series WHERE name = :name',
            ['name' => 'Series With A Kept Logo'],
        );
        self::assertIsString($logo);
        self::assertStringStartsWith('competitions/', $logo);
    }

    /**
     * @return array<string, mixed>
     */
    private static function competitionRow(string $name): array
    {
        $row = self::getContainer()->get(Connection::class)->fetchAssociative(
            'SELECT location, is_online, date_from, date_to FROM competition WHERE name = :name',
            ['name' => $name],
        );
        self::assertIsArray($row);

        return $row;
    }
}
