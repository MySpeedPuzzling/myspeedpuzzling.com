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

    /**
     * The download URLs end in ".../download/xlsx" or ".../library/xlsx" without a dot, so Turbo Drive takes them
     * for pages: it fetches the file, gets no HTML back and loads the URL again - every export would be built twice.
     */
    public function testDownloadLinksBypassTurboDrive(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/export-puzzler-data/' . PlayerFixture::PLAYER_REGULAR);

        $downloadLinks = $crawler->filter('a[href^="/en/export-puzzler-data/' . PlayerFixture::PLAYER_REGULAR . '/"]');
        self::assertCount(8, $downloadLinks);

        foreach ($downloadLinks as $link) {
            self::assertInstanceOf(\DOMElement::class, $link);
            self::assertSame('false', $link->getAttribute('data-turbo'), $link->getAttribute('href'));
        }
    }

    public function testLoggedInUserCannotAccessOtherPlayerExportPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/export-puzzler-data/' . PlayerFixture::PLAYER_ADMIN);
        $this->assertResponseStatusCodeSame(403);
    }
}
