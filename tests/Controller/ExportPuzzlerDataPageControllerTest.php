<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ExportPuzzlerDataPageControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/export-puzzler-data/' . PlayerFixture::PLAYER_REGULAR);
        $this->assertResponseRedirects();
    }

    public function testLoggedInUserCanAccessOwnExportPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/export-puzzler-data/' . PlayerFixture::PLAYER_REGULAR);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorTextContains('h1', 'Export Your Data');
    }

    public function testLoggedInUserCannotAccessOtherPlayerExportPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/export-puzzler-data/' . PlayerFixture::PLAYER_ADMIN);
        $this->assertResponseStatusCodeSame(403);
    }
}
