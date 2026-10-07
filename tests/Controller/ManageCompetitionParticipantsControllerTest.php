<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Message\ChangeCompetitionRegistrationSettings;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

final class ManageCompetitionParticipantsControllerTest extends WebTestCase
{
    /**
     * @return array<string, array{string}>
     */
    public static function provideLocalizedPagePaths(): array
    {
        return [
            'cs' => ['/sprava-ucastniku-udalosti/'],
            'en' => ['/en/manage-event-participants/'],
            'es' => ['/es/manage-event-participants/'],
            'ja' => ['/ja/manage-event-participants/'],
            'fr' => ['/fr/manage-event-participants/'],
            'de' => ['/de/manage-event-participants/'],
        ];
    }

    /**
     * The page links to the import/export/template routes - it used to 500 in
     * every locale those routes were not defined for (production, /fr/).
     */
    #[DataProvider('provideLocalizedPagePaths')]
    public function testMaintainerCanAccessPageInEveryLocale(string $path): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', $path . CompetitionFixture::COMPETITION_UNAPPROVED);

        $this->assertResponseIsSuccessful();
    }

    public function testAnonymousUserIsRedirectedToLogin(): void
    {
        $browser = self::createClient();
        $browser->request('GET', '/en/manage-event-participants/' . CompetitionFixture::COMPETITION_UNAPPROVED);

        $this->assertResponseRedirects();
    }

    public function testMaintainerCanAccessPage(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/manage-event-participants/' . CompetitionFixture::COMPETITION_UNAPPROVED);

        $this->assertResponseIsSuccessful();
    }

    /**
     * Managed registration: an in-person event offers the check-in page, an online one does not - nobody walks in.
     */
    public function testCheckInIsOfferedOnlyForInPersonManagedEvents(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $bus = self::getContainer()->get(MessageBusInterface::class);

        foreach ([CompetitionFixture::COMPETITION_UNAPPROVED, CompetitionFixture::COMPETITION_RECURRING_ONLINE] as $competitionId) {
            $bus->dispatch(new ChangeCompetitionRegistrationSettings($competitionId, true, null, null, null, 'Europe/Prague', null, null));
        }

        $browser->request('GET', '/en/manage-event-participants/' . CompetitionFixture::COMPETITION_UNAPPROVED);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorExists('a[href="/en/event-check-in/' . CompetitionFixture::COMPETITION_UNAPPROVED . '"]');

        $browser->request('GET', '/en/manage-event-participants/' . CompetitionFixture::COMPETITION_RECURRING_ONLINE);
        $this->assertResponseIsSuccessful();
        $this->assertSelectorNotExists('a[href*="/event-check-in/"]');
    }

    public function testNonMaintainerDenied(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $browser->request('GET', '/en/manage-event-participants/' . CompetitionFixture::COMPETITION_WJPC_2024);

        $this->assertResponseStatusCodeSame(403);
    }
}
