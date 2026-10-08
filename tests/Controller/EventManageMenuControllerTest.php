<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * The ⋯ menu (docs/features/events-page/README.md, "The ⋯ menu"; route event_manage_menu): only the items the viewer
 * may use, 403 for everybody else.
 */
final class EventManageMenuControllerTest extends WebTestCase
{
    public function testGuestsAreSentToSignIn(): void
    {
        $browser = self::createClient();

        $browser->request('GET', '/en/event-actions/competition/' . CompetitionFixture::COMPETITION_RECURRING_ONLINE);

        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $browser->getResponse()->headers->get('Location'));
    }

    public function testOtherPlayersAreRefused(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $browser->request('GET', '/en/event-actions/competition/' . CompetitionFixture::COMPETITION_RECURRING_ONLINE, server: $this->frameHeader());
        self::assertResponseStatusCodeSame(403);

        $browser->request('GET', '/en/event-actions/series/' . EventsPageFixture::SERIES_HARBOR_NIGHTS, server: $this->frameHeader());
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheOwnerOfAnEventGetsEveryItemInTheFrame(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/event-actions/competition/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP . '?return=' . rawurlencode('/en/events?country=cz'), server: $this->frameHeader());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('no-store', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertStringContainsString('private', (string) $browser->getResponse()->headers->get('Cache-Control'));
        self::assertStringNotContainsString('<html', (string) $browser->getResponse()->getContent());
        self::assertCount(1, $crawler->filter('turbo-frame#event-manage-menu'));

        self::assertSame(['Edit event', 'Rounds & puzzles', 'Participants', 'Results', 'Page content', 'Delete'], $this->itemLabels($crawler));

        // Every link leaves the frame and comes back to the page it was opened from
        $crawler->filter('.ev-actions a.ev-action')->each(static function (Crawler $link): void {
            self::assertSame('_top', $link->attr('data-turbo-frame'));
            parse_str((string) parse_url((string) $link->attr('href'), PHP_URL_QUERY), $query);
            self::assertSame('/en/events?country=cz', $query['return'] ?? null);
        });

        $delete = $crawler->filter('details.ev-confirm form');
        self::assertSame('/en/delete-event/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP, $delete->attr('action'));
        self::assertSame('_top', $delete->attr('data-turbo-frame'));
        self::assertNotEmpty($delete->filter('input[name="_token"]')->attr('value'));
        self::assertSame('/en/events?country=cz', $delete->filter('input[name="return"]')->attr('value'));
    }

    public function testNoResultsItemWithoutRounds(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/event-actions/competition/' . EventsPageFixture::COMPETITION_RIVERSIDE_OPEN, server: $this->frameHeader());

        self::assertResponseIsSuccessful();
        self::assertSame(['Edit event', 'Rounds & puzzles', 'Participants', 'Page content', 'Delete'], $this->itemLabels($crawler));
    }

    public function testAMaintainerOfASingleEditionCannotDeleteItNorManageItsSeries(): void
    {
        $browser = self::createClient();
        $this->maintainEdition(PlayerFixture::PLAYER_WITH_FAVORITES, EventsPageFixture::EDITION_HARBOR_1);
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $crawler = $browser->request('GET', '/en/event-actions/competition/' . EventsPageFixture::EDITION_HARBOR_1, server: $this->frameHeader());

        self::assertResponseIsSuccessful();
        self::assertSame(['Edit event', 'Rounds & puzzles', 'Participants', 'Results', 'Page content'], $this->itemLabels($crawler));
        self::assertCount(0, $crawler->filter('input[name="_token"]'));

        $browser->request('GET', '/en/event-actions/series/' . EventsPageFixture::SERIES_HARBOR_NIGHTS, server: $this->frameHeader());
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheSeriesMenu(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/event-actions/series/' . EventsPageFixture::SERIES_HARBOR_NIGHTS, server: $this->frameHeader());

        self::assertResponseIsSuccessful();
        self::assertSame(['Manage series', 'Add edition', 'Page content', 'Delete series'], $this->itemLabels($crawler));
        self::assertSame('/en/delete-series/' . EventsPageFixture::SERIES_HARBOR_NIGHTS, $crawler->filter('details.ev-confirm form')->attr('action'));

        // An edition is deleted through its own route
        $crawler = $browser->request('GET', '/en/event-actions/competition/' . EventsPageFixture::EDITION_HARBOR_2, server: $this->frameHeader());
        self::assertSame('/en/delete-edition/' . EventsPageFixture::EDITION_HARBOR_2, $crawler->filter('details.ev-confirm form')->attr('action'));
    }

    public function testAdminsApproveOrRejectWhatWaitsForApproval(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/event-actions/competition/' . CompetitionFixture::COMPETITION_UNAPPROVED, server: $this->frameHeader());

        self::assertResponseIsSuccessful();
        self::assertSame(['Approve event', 'Reject event…', 'Edit event', 'Rounds & puzzles', 'Participants', 'Page content', 'Delete'], $this->itemLabels($crawler));
        self::assertStringContainsString('waiting for approval', $crawler->filter('.ev-menu-kind')->text());
        self::assertCount(1, $crawler->filter('form[action="/admin/competitions/' . CompetitionFixture::COMPETITION_UNAPPROVED . '/approve"]'));
        self::assertCount(1, $crawler->filter('form[action="/admin/competitions/' . CompetitionFixture::COMPETITION_UNAPPROVED . '/reject"] textarea[name="reason"][required]'));

        // An edition waits for its series: the series is approved or rejected
        $crawler = $browser->request('GET', '/en/event-actions/competition/' . CompetitionSeriesFixture::EDITION_UNAPPROVED_1, server: $this->frameHeader());
        self::assertSame('Approve series', $this->itemLabels($crawler)[0]);
        self::assertCount(1, $crawler->filter('form[action="/admin/series/' . CompetitionSeriesFixture::SERIES_UNAPPROVED . '/approve"]'));

        $crawler = $browser->request('GET', '/en/event-actions/series/' . CompetitionSeriesFixture::SERIES_UNAPPROVED, server: $this->frameHeader());
        self::assertSame(['Approve series', 'Reject series…'], array_slice($this->itemLabels($crawler), 0, 2));

        // Nothing to approve on a public event
        $crawler = $browser->request('GET', '/en/event-actions/competition/' . EventsPageFixture::COMPETITION_RIVERSIDE_OPEN, server: $this->frameHeader());
        self::assertCount(0, $crawler->filter('form[action*="/approve"]'));
    }

    public function testOrganisersNeverSeeApproval(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/event-actions/competition/' . CompetitionFixture::COMPETITION_UNAPPROVED, server: $this->frameHeader());

        self::assertResponseIsSuccessful();
        self::assertCount(0, $crawler->filter('form[action*="/admin/"]'));
    }

    public function testAnUnknownEventIsNotFound(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $browser->request('GET', '/en/event-actions/competition/018d0040-0000-0000-0000-0000000000ff', server: $this->frameHeader());
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/en/event-actions/series/018d0040-0000-0000-0000-0000000000ff', server: $this->frameHeader());
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/en/event-actions/puzzle/' . EventsPageFixture::COMPETITION_RIVERSIDE_OPEN);
        self::assertResponseStatusCodeSame(404);
    }

    public function testWithoutJavaScriptTheMenuIsAPageWithABackLink(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);

        $crawler = $browser->request('GET', '/en/event-actions/competition/' . CompetitionFixture::COMPETITION_RECURRING_ONLINE . '?return=' . rawurlencode('/en/you-organize') . '&return_title=You%20organize');

        self::assertResponseIsSuccessful();
        self::assertSelectorExists('meta[name="robots"][content="noindex, nofollow"]');
        self::assertCount(0, $crawler->filter('turbo-frame#event-manage-menu'));
        self::assertSame('/en/you-organize', $crawler->filter('.ev-menu-back a')->attr('href'));
        self::assertSelectorTextContains('.ev-menu-back', 'Back to You organize');

        // An off-site return falls back to the events page
        $crawler = $browser->request('GET', '/en/event-actions/competition/' . CompetitionFixture::COMPETITION_RECURRING_ONLINE . '?return=' . rawurlencode('//evil.example/'));
        self::assertSame('/en/events', $crawler->filter('.ev-menu-back a')->attr('href'));
        self::assertSelectorTextContains('.ev-menu-back', 'Back to Events');
    }

    /**
     * @return list<string>
     */
    private function itemLabels(Crawler $crawler): array
    {
        return $crawler->filter('.ev-actions > li > a.ev-action, .ev-actions > li > form > button.ev-action, .ev-actions > li > details > summary')->each(
            static fn (Crawler $item): string => trim($item->text()),
        );
    }

    /**
     * @return array<string, string>
     */
    private function frameHeader(): array
    {
        return ['HTTP_TURBO_FRAME' => 'event-manage-menu'];
    }

    private function maintainEdition(string $playerId, string $competitionId): void
    {
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->insert('competition_maintainer', ['competition_id' => $competitionId, 'player_id' => $playerId]);
    }
}
