<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
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

        $delete = $crawler->filter('details[data-delete] form');
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
        // Back to draft is for whoever edits it; moving it to another series only for its series' team
        self::assertSame(['Edit event', 'Rounds & puzzles', 'Participants', 'Results', 'Page content', 'Unpublish…'], $this->itemLabels($crawler));
        self::assertCount(0, $crawler->filter('details[data-delete]'));

        $browser->request('GET', '/en/event-actions/series/' . EventsPageFixture::SERIES_HARBOR_NIGHTS, server: $this->frameHeader());
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheSeriesMenu(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/event-actions/series/' . EventsPageFixture::SERIES_HARBOR_NIGHTS, server: $this->frameHeader());

        self::assertResponseIsSuccessful();
        self::assertSame(['Manage series', 'Add edition', 'Add several dates', 'Page content', 'Turn into an organization', 'Unpublish…', 'Delete series'], $this->itemLabels($crawler));
        self::assertSame('/en/delete-series/' . EventsPageFixture::SERIES_HARBOR_NIGHTS, $crawler->filter('details[data-delete] form')->attr('action'));

        // An edition is deleted through its own route
        $crawler = $browser->request('GET', '/en/event-actions/competition/' . EventsPageFixture::EDITION_HARBOR_2, server: $this->frameHeader());
        self::assertSame('/en/delete-edition/' . EventsPageFixture::EDITION_HARBOR_2, $crawler->filter('details[data-delete] form')->attr('action'));
    }

    public function testAdminsApproveOrRejectWhatWaitsForApproval(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/event-actions/competition/' . CompetitionFixture::COMPETITION_UNAPPROVED, server: $this->frameHeader());

        self::assertResponseIsSuccessful();
        self::assertSame(['Approve event', 'Reject event…', 'Edit event', 'Rounds & puzzles', 'Participants', 'Page content', 'Unpublish…', 'Delete'], $this->itemLabels($crawler));
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
     * docs/features/organizations/README.md "The ⋯ menu": a draft is published from the menu, first; nothing to approve
     */
    public function testADraftIsPublishedFromTheMenu(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/event-actions/competition/' . OrganizationFixture::COMPETITION_DRAFT_NIGHT . '?return=' . rawurlencode('/en/events'), server: $this->frameHeader());

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('Draft', $crawler->filter('.ev-menu-kind')->text());
        self::assertSame(['Publish', 'Edit event', 'Rounds & puzzles', 'Participants', 'Results', 'Page content', 'Delete'], $this->itemLabels($crawler));

        $publish = $crawler->filter('button[data-publish]')->closest('form');
        self::assertNotNull($publish);
        self::assertSame('/en/publish-event/' . OrganizationFixture::COMPETITION_DRAFT_NIGHT, $publish->attr('action'));
        self::assertSame('_top', $publish->attr('data-turbo-frame'));
        self::assertNotEmpty($publish->filter('input[name="_token"]')->attr('value'));
        self::assertSame('/en/events', $publish->filter('input[name="return"]')->attr('value'));
    }

    public function testAnEditionOfADraftSeriesIsPublishedThroughItsSeries(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        // Not a draft itself: no Publish of its own, and nothing to unpublish while its series hides it
        $crawler = $browser->request('GET', '/en/event-actions/competition/' . OrganizationFixture::EDITION_QUIET_PINES_1, server: $this->frameHeader());
        self::assertSame('Publish series', $this->itemLabels($crawler)[0]);
        self::assertCount(0, $crawler->filter('button[data-publish]'));
        self::assertCount(0, $crawler->filter('details[data-unpublish]'));
        self::assertSame('/en/publish-series/' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT, $crawler->filter('button[data-publish-series]')->closest('form')?->attr('action'));

        // A draft edition of a published series: its own Publish, and its series' team may move it
        $crawler = $browser->request('GET', '/en/event-actions/competition/' . OrganizationFixture::EDITION_LANTERN_DRAFT, server: $this->frameHeader());
        $labels = $this->itemLabels($crawler);
        self::assertSame('Publish', $labels[0]);
        self::assertNotContains('Publish series', $labels);
        self::assertContains('Move to another series', $labels);
        self::assertSame('/en/move-edition/' . OrganizationFixture::EDITION_LANTERN_DRAFT, parse_url((string) $crawler->filter('a.ev-action:contains("Move to another series")')->attr('href'), PHP_URL_PATH));
    }

    public function testUnpublishIsOfferedOnlyWhenNothingKeepsItPublic(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        // People joined the Results Cup and it has official results: the menu says why instead
        $crawler = $browser->request('GET', '/en/event-actions/competition/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP, server: $this->frameHeader());
        self::assertCount(0, $crawler->filter('details[data-unpublish]'));
        self::assertSame("Can't go back to draft: people have joined it, it has official results.", trim($crawler->filter('[data-cannot-unpublish]')->text()));

        // The Spring Open: nobody joined yet - back to draft, confirmed in place
        $crawler = $browser->request('GET', '/en/event-actions/competition/' . OrganizationFixture::COMPETITION_RIVERBEND_OPEN, server: $this->frameHeader());
        $unpublish = $crawler->filter('details[data-unpublish] form');
        self::assertSame('/en/unpublish-event/' . OrganizationFixture::COMPETITION_RIVERBEND_OPEN, $unpublish->attr('action'));
        self::assertNotEmpty($unpublish->filter('input[name="_token"]')->attr('value'));
        self::assertStringContainsString('only you and your team can see it', $unpublish->text());
    }

    public function testTheOrganizationMenu(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        // Its creator: not empty, so no Delete
        $crawler = $browser->request('GET', '/en/event-actions/organization/' . OrganizationFixture::ORGANIZATION_RIVERBEND, server: $this->frameHeader());
        self::assertResponseIsSuccessful();
        self::assertStringStartsWith('Organization', trim($crawler->filter('.ev-menu-kind')->text()));
        self::assertSame(['Edit organization', 'Add event', 'Unpublish…'], $this->itemLabels($crawler));
        self::assertSame('/en/unpublish-organization/' . OrganizationFixture::ORGANIZATION_RIVERBEND, $crawler->filter('details[data-unpublish] form')->attr('action'));

        // Somebody not on its team
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $browser->request('GET', '/en/event-actions/organization/' . OrganizationFixture::ORGANIZATION_RIVERBEND, server: $this->frameHeader());
        self::assertResponseStatusCodeSame(403);
    }

    public function testOnlyTheCreatorDeletesAnEmptyOrganization(): void
    {
        $browser = self::createClient();
        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $connection->insert('organization_maintainer', [
            'organization_id' => OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT,
            'player_id' => PlayerFixture::PLAYER_REGULAR,
        ]);

        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $crawler = $browser->request('GET', '/en/event-actions/organization/' . OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT, server: $this->frameHeader());
        self::assertSame(['Publish', 'Edit organization', 'Add event', 'Delete organization'], $this->itemLabels($crawler));
        self::assertSame('/en/delete-organization/' . OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT, $crawler->filter('details[data-delete] form')->attr('action'));

        // A maintainer of it
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_REGULAR);
        $crawler = $browser->request('GET', '/en/event-actions/organization/' . OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT, server: $this->frameHeader());
        self::assertResponseIsSuccessful();
        self::assertSame(['Publish', 'Edit organization', 'Add event'], $this->itemLabels($crawler));
    }

    public function testAdminsApproveWhatWaitsButNeverADraft(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_ADMIN);

        $crawler = $browser->request('GET', '/en/event-actions/organization/' . OrganizationFixture::ORGANIZATION_MAPLE_PENDING, server: $this->frameHeader());
        self::assertSame(['Approve organization', 'Reject organization…'], array_slice($this->itemLabels($crawler), 0, 2));
        self::assertCount(1, $crawler->filter('form[action="/admin/organizations/' . OrganizationFixture::ORGANIZATION_MAPLE_PENDING . '/approve"]'));
        self::assertCount(1, $crawler->filter('form[action="/admin/organizations/' . OrganizationFixture::ORGANIZATION_MAPLE_PENDING . '/reject"] textarea[name="reason"][required]'));

        // Waiting for approval and a draft: submitted only by publishing it
        foreach (
            [
            ['organization', OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT],
            ['competition', OrganizationFixture::COMPETITION_WILLOW_PENDING_DRAFT],
            ] as [$kind, $id]
        ) {
            $crawler = $browser->request('GET', '/en/event-actions/' . $kind . '/' . $id, server: $this->frameHeader());
            self::assertResponseIsSuccessful();
            self::assertCount(0, $crawler->filter('form[action*="/approve"]'), $id);
            self::assertSame('Publish', $this->itemLabels($crawler)[0], $id);
        }
    }

    public function testTheSeriesMenuOffersSeveralDatesAndTurningIntoAnOrganization(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        // Under an organization already: no "Turn into an organization"
        $crawler = $browser->request('GET', '/en/event-actions/series/' . OrganizationFixture::SERIES_LANTERN_NIGHTS, server: $this->frameHeader());
        $labels = $this->itemLabels($crawler);
        self::assertContains('Add several dates', $labels);
        self::assertNotContains('Turn into an organization', $labels);
        self::assertSame('/en/add-editions/' . OrganizationFixture::SERIES_LANTERN_NIGHTS, parse_url((string) $crawler->filter('a.ev-action:contains("Add several dates")')->attr('href'), PHP_URL_PATH));

        // A draft series without an organization
        $crawler = $browser->request('GET', '/en/event-actions/series/' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT, server: $this->frameHeader());
        self::assertSame('Publish', $this->itemLabels($crawler)[0]);
        self::assertSame('/en/publish-series/' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT, $crawler->filter('button[data-publish]')->closest('form')?->attr('action'));
        self::assertSame('/en/series-to-organization/' . OrganizationFixture::SERIES_QUIET_PINES_DRAFT, parse_url((string) $crawler->filter('a.ev-action:contains("Turn into an organization")')->attr('href'), PHP_URL_PATH));
    }

    public function testTheRoundsPageLinksMovingARound(): void
    {
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $crawler = $browser->request('GET', '/en/manage-event-rounds/' . OrganizationFixture::COMPETITION_DRAFT_NIGHT);
        self::assertResponseIsSuccessful();

        $move = $crawler->filter('a[data-move-round-link]');
        self::assertCount(1, $move);
        self::assertSame('/en/move-round/' . OrganizationFixture::ROUND_DRAFT_NIGHT, parse_url((string) $move->attr('href'), PHP_URL_PATH));
        self::assertStringContainsString('Move to another event or edition', $move->text());
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
