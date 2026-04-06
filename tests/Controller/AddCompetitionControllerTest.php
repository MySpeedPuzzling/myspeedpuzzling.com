<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class AddCompetitionControllerTest extends WebTestCase
{
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
}
