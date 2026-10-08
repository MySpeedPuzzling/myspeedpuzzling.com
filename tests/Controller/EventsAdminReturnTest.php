<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The optional `return` of approve/reject (admin) and delete (docs/features/events-page/implementation-plan.md, 2.C):
 * the ⋯ menu and "You organize" come back to where they were opened; an off-site value is ignored and the old
 * destination stays.
 */
final class EventsAdminReturnTest extends WebTestCase
{
    public function testApproveReturns(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('POST', '/admin/competitions/' . CompetitionFixture::COMPETITION_UNAPPROVED . '/approve', ['return' => '/en/events?country=cz']);
        self::assertResponseRedirects('/en/events?country=cz');

        $browser->request('POST', '/admin/series/' . CompetitionSeriesFixture::SERIES_UNAPPROVED . '/approve', ['return' => '/en/you-organize']);
        self::assertResponseRedirects('/en/you-organize');
    }

    public function testApproveIgnoresAnOffSiteReturn(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('POST', '/admin/competitions/' . CompetitionFixture::COMPETITION_UNAPPROVED . '/approve', ['return' => 'https://evil.example/']);
        self::assertResponseRedirects('/admin/competition-approvals');

        $browser->request('POST', '/admin/series/' . CompetitionSeriesFixture::SERIES_UNAPPROVED . '/approve');
        self::assertResponseRedirects('/admin/competition-approvals');
    }

    public function testRejectReturnsAndStillNeedsAReason(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        // No reason: nothing happens, back to where it came from with the message
        $browser->request('POST', '/admin/competitions/' . CompetitionFixture::COMPETITION_UNAPPROVED . '/reject', ['reason' => ' ', 'return' => '/en/events']);
        self::assertResponseRedirects('/en/events');
        $browser->followRedirect();
        self::assertSelectorTextContains('main', 'Unapproved Puzzle Event');

        $browser->request('POST', '/admin/competitions/' . CompetitionFixture::COMPETITION_UNAPPROVED . '/reject', ['reason' => 'A duplicate.', 'return' => '/en/events']);
        self::assertResponseRedirects('/en/events');
        $browser->followRedirect();
        self::assertSelectorTextNotContains('main', 'Unapproved Puzzle Event');

        $browser->request('POST', '/admin/series/' . CompetitionSeriesFixture::SERIES_UNAPPROVED . '/reject', ['reason' => 'A duplicate.', 'return' => '//evil.example/']);
        self::assertResponseRedirects('/admin/competition-approvals');
    }

    public function testDeleteAnEventReturns(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $token = $this->deleteToken($browser, 'competition', CompetitionFixture::COMPETITION_UNAPPROVED);
        $browser->request('POST', '/en/delete-event/' . CompetitionFixture::COMPETITION_UNAPPROVED, ['_token' => $token, 'return' => '/en/you-organize']);

        self::assertResponseRedirects('/en/you-organize');
        $crawler = $browser->followRedirect();
        self::assertCount(0, $crawler->filter('[data-organized-id="' . CompetitionFixture::COMPETITION_UNAPPROVED . '"]'));
    }

    public function testDeleteAnEditionReturnsElseGoesToItsSeries(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $token = $this->deleteToken($browser, 'competition', EventsPageFixture::EDITION_HARBOR_2);
        $browser->request('POST', '/en/delete-edition/' . EventsPageFixture::EDITION_HARBOR_2, ['_token' => $token, 'return' => '/en/events']);
        self::assertResponseRedirects('/en/events');

        $token = $this->deleteToken($browser, 'competition', EventsPageFixture::EDITION_HARBOR_3);
        $browser->request('POST', '/en/delete-edition/' . EventsPageFixture::EDITION_HARBOR_3, ['_token' => $token, 'return' => '/\\evil.example']);
        self::assertResponseRedirects('/en/manage-series/' . EventsPageFixture::SERIES_HARBOR_NIGHTS);
    }

    public function testDeleteASeriesIgnoresAnOffSiteReturn(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $token = $this->deleteToken($browser, 'series', EventsPageFixture::SERIES_SUMMIT_LEAGUE);
        $browser->request('POST', '/en/delete-series/' . EventsPageFixture::SERIES_SUMMIT_LEAGUE, ['_token' => $token, 'return' => '%2F%2Fevil.example']);

        self::assertResponseRedirects('/en/events');
    }

    /**
     * The per-id session token, as the ⋯ menu renders it
     */
    private function deleteToken(KernelBrowser $browser, string $kind, string $id): string
    {
        $crawler = $browser->request('GET', '/en/event-actions/' . $kind . '/' . $id, server: ['HTTP_TURBO_FRAME' => 'event-manage-menu']);
        self::assertResponseIsSuccessful();

        return (string) $crawler->filter('details[data-delete] form input[name="_token"]')->attr('value');
    }
}
