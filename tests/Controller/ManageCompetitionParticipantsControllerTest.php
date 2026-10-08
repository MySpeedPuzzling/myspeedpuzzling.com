<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The old participants page is retired for the participants spreadsheet (participants-spreadsheet.md D12): its URLs -
 * bookmarks, old e-mails - lead to the sheet, for the event's organisers only, in every locale.
 */
final class ManageCompetitionParticipantsControllerTest extends WebTestCase
{
    /**
     * @return array<string, array{string, string}>
     */
    public static function provideLocalizedPaths(): array
    {
        return [
            'cs' => ['/sprava-ucastniku-udalosti/', '/tabulka-ucastniku/'],
            'en' => ['/en/manage-event-participants/', '/en/participants-sheet/'],
            'es' => ['/es/manage-event-participants/', '/es/participants-sheet/'],
            'ja' => ['/ja/manage-event-participants/', '/ja/participants-sheet/'],
            'fr' => ['/fr/manage-event-participants/', '/fr/participants-sheet/'],
            'de' => ['/de/manage-event-participants/', '/de/participants-sheet/'],
        ];
    }

    #[DataProvider('provideLocalizedPaths')]
    public function testTheOrganiserLandsOnTheSheetInEveryLocale(string $oldPath, string $sheetPath): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', $oldPath . CompetitionFixture::COMPETITION_UNAPPROVED);

        self::assertResponseRedirects($sheetPath . CompetitionFixture::COMPETITION_UNAPPROVED, 302);
        $browser->followRedirect();
        self::assertResponseIsSuccessful();
    }

    public function testAValidatedReturnAddressGoesAlong(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/manage-event-participants/' . CompetitionFixture::COMPETITION_UNAPPROVED . '?return=/en/series/x&return_title=My%20series');
        self::assertResponseRedirects('/en/participants-sheet/' . CompetitionFixture::COMPETITION_UNAPPROVED . '?return=/en/series/x&return_title=My%20series', 302);

        // Another site's address is dropped - its title with it
        $browser->request('GET', '/en/manage-event-participants/' . CompetitionFixture::COMPETITION_UNAPPROVED . '?return=//evil.example&return_title=Evil');
        self::assertResponseRedirects('/en/participants-sheet/' . CompetitionFixture::COMPETITION_UNAPPROVED, 302);
    }

    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/manage-event-participants/' . CompetitionFixture::COMPETITION_UNAPPROVED);

        self::assertResponseRedirects();
        self::assertStringNotContainsString('participants-sheet', (string) $browser->getResponse()->headers->get('Location'));
    }

    public function testNonMaintainerDenied(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/manage-event-participants/' . CompetitionFixture::COMPETITION_WJPC_2024);

        self::assertResponseStatusCodeSame(403);
    }
}
