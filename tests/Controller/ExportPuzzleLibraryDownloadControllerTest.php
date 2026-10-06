<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

final class ExportPuzzleLibraryDownloadControllerTest extends WebTestCase
{
    use QueryCountAssertions;

    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/export-puzzler-data/' . PlayerFixture::PLAYER_REGULAR . '/library/json');

        $this->assertResponseRedirects();
    }

    /**
     * @return iterable<string, array{string, string, string}>
     */
    public static function formats(): iterable
    {
        yield 'json' => ['json', 'application/json', 'json'];
        yield 'xlsx' => ['xlsx', 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'xlsx'];
        yield 'csv' => ['csv', 'application/zip', 'zip'];
        yield 'xml' => ['xml', 'application/xml', 'xml'];
    }

    #[DataProvider('formats')]
    public function testDownloadsThePlayersOwnLibrary(string $format, string $contentType, string $extension): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', '/en/export-puzzler-data/' . PlayerFixture::PLAYER_WITH_STRIPE . '/library/' . $format);

        $this->assertResponseIsSuccessful();
        $this->assertResponseHeaderSame('Content-Type', $contentType);
        $disposition = $browser->getResponse()->headers->get('Content-Disposition') ?? '';
        self::assertMatchesRegularExpression('/^attachment; filename="speedpuzzling-library-\d{4}-\d{2}-\d{2}\.' . $extension . '"$/', $disposition);

        $cacheControl = $browser->getResponse()->headers->get('Cache-Control') ?? '';
        self::assertStringContainsString('no-store', $cacheControl);
        self::assertStringContainsString('private', $cacheControl);
    }

    public function testCannotDownloadAnotherPlayersLibrary(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/export-puzzler-data/' . PlayerFixture::PLAYER_WITH_STRIPE . '/library/json');

        $this->assertResponseStatusCodeSame(403);
    }

    public function testInvalidFormatReturns404(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/export-puzzler-data/' . PlayerFixture::PLAYER_REGULAR . '/library/pdf');

        $this->assertResponseStatusCodeSame(404);
    }

    /**
     * One statement per section plus two puzzle-level ones, whatever the library size - no per-row lookups.
     * Both players are members, so the session/profile part of the request is the same for both.
     */
    public function testQueryCountDoesNotGrowWithTheLibrary(): void
    {
        $small = $this->countExportQueries(PlayerFixture::PLAYER_ADMIN);
        $large = $this->countExportQueries(PlayerFixture::PLAYER_WITH_STRIPE);

        self::assertSame($small, $large, 'A larger library must not cost more queries.');
        self::assertLessThanOrEqual(25, $large);
    }

    private function countExportQueries(string $playerId): int
    {
        self::ensureKernelShutdown();
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, $playerId);

        $this->startCountingQueries($browser);
        $browser->request('GET', '/en/export-puzzler-data/' . $playerId . '/library/json');
        $this->assertResponseIsSuccessful();

        return $this->queryCount($browser);
    }
}
