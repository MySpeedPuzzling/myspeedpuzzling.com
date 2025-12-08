<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ExportPuzzlerDataDownloadControllerTest extends WebTestCase
{
    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/export-puzzler-data/' . PlayerFixture::PLAYER_REGULAR . '/download/json');
        $this->assertResponseRedirects();
    }

    public function testDownloadJsonReturnsCorrectContentType(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/export-puzzler-data/' . PlayerFixture::PLAYER_REGULAR . '/download/json');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/json');
        $this->assertStringContainsString('attachment', $browser->getResponse()->headers->get('Content-Disposition') ?? '');
    }

    public function testDownloadXlsxReturnsCorrectContentType(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/export-puzzler-data/' . PlayerFixture::PLAYER_REGULAR . '/download/xlsx');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        $this->assertStringContainsString('attachment', $browser->getResponse()->headers->get('Content-Disposition') ?? '');
    }

    public function testDownloadCsvReturnsCorrectContentType(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/export-puzzler-data/' . PlayerFixture::PLAYER_REGULAR . '/download/csv');
        $this->assertResponseIsSuccessful();
        $contentType = $browser->getResponse()->headers->get('Content-Type') ?? '';
        $this->assertStringContainsString('text/csv', $contentType);
        $this->assertStringContainsString('attachment', $browser->getResponse()->headers->get('Content-Disposition') ?? '');
    }

    public function testDownloadXmlReturnsCorrectContentType(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/export-puzzler-data/' . PlayerFixture::PLAYER_REGULAR . '/download/xml');
        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', 'application/xml');
        $this->assertStringContainsString('attachment', $browser->getResponse()->headers->get('Content-Disposition') ?? '');
    }

    public function testInvalidFormatReturns404(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/export-puzzler-data/' . PlayerFixture::PLAYER_REGULAR . '/download/pdf');
        $this->assertResponseStatusCodeSame(404);
    }

    public function testCannotDownloadOtherPlayerData(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/export-puzzler-data/' . PlayerFixture::PLAYER_ADMIN . '/download/json');
        $this->assertResponseStatusCodeSame(403);
    }
}
