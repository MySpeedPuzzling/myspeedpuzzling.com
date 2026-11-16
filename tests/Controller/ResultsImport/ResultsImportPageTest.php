<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\ResultsImport;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Entity\EventResultsSheet;
use SpeedPuzzling\Web\Message\ClaimEventResultSlot;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\ResultsImportScenario;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The results import page (docs/features/event-results-import/results-import-page.md): who reaches it, "Check sheet"
 * keeping the sheet as read (Google faked), every status of an event against what is stored, the plain-words problems,
 * the soft warnings, the preview with its ranking and its team differences (P4 wording), and the kill switch.
 * Made-up events and teams.
 */
final class ResultsImportPageTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;
    use ResultsImportScenario;

    private const string SLUG = OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG;

    private KernelBrowser $browser;

    private EventResultsSheet $source;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->source = $this->createResultsSource(self::getContainer());
        $this->fakeGoogle(self::getContainer());
    }

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();
        parent::tearDown();
    }

    private function pageUrl(): string
    {
        return '/en/organizations/' . self::SLUG . '/results-import/' . $this->source->id->toString();
    }

    public function testTheTeamGoesStraightToItsOnlySource(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        $this->browser->request('GET', '/en/organizations/' . self::SLUG . '/results-import');

        self::assertResponseRedirects($this->pageUrl());

        $this->browser->followRedirect();
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-results-import-never-checked]');
        // The format's guide in the help box
        self::assertSelectorTextContains('[data-results-import-guide]', 'Contest Name');
        self::assertSame('noindex, nofollow', $this->browser->getCrawler()->filter('meta[name="robots"]')->attr('content'));
    }

    public function testTheCreatorAndAnAdminMayUseIt(): void
    {
        foreach ([PlayerFixture::PLAYER_WITH_STRIPE, PlayerFixture::PLAYER_ADMIN] as $playerId) {
            TestingLogin::asPlayer($this->browser, $playerId);
            $this->browser->request('GET', $this->pageUrl());
            self::assertResponseIsSuccessful();
        }
    }

    public function testAnotherPlayerIsRefusedAndAGuestIsAskedToSignIn(): void
    {
        $this->browser->request('GET', $this->pageUrl());
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->browser->getResponse()->headers->get('Location'));

        $this->browser->request('POST', $this->pageUrl() . '/check');
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->browser->getResponse()->headers->get('Location'));

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->browser->request('GET', $this->pageUrl());
        self::assertResponseStatusCodeSame(403);
        $this->browser->request('GET', '/en/organizations/' . self::SLUG . '/results-import');
        self::assertResponseStatusCodeSame(403);
        $this->browser->request('POST', $this->pageUrl() . '/check');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAnOrganizationWithoutASourceHasNoPageAndASourceBelongsToItsOrganization(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        // Harbor Club: PLAYER_WITH_STRIPE's draft organization, no results source
        $harborSlug = self::getContainer()->get(Connection::class)->fetchOne('SELECT slug FROM organization WHERE id = :id', ['id' => OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT]);
        self::assertIsString($harborSlug);

        // Riverbend's source under another organization's address
        $this->browser->request('GET', '/en/organizations/' . $harborSlug . '/results-import');
        self::assertResponseStatusCodeSame(404);
        $this->browser->request('GET', '/en/organizations/' . $harborSlug . '/results-import/' . $this->source->id->toString());
        self::assertResponseStatusCodeSame(404);
    }

    public function testTheKillSwitchHidesThePageAndItsLink(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->browser->request('GET', '/en/event-actions/organization/' . OrganizationFixture::ORGANIZATION_RIVERBEND);
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/results-import', (string) $this->browser->getResponse()->getContent());

        self::ensureKernelShutdown();
        $this->overrideFeatureFlagEnv('RESULTS_IMPORT_PAGE', false);
        $browser = self::createClient();
        TestingLogin::asPlayer($browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $browser->request('GET', $this->pageUrl());
        self::assertResponseStatusCodeSame(404);
        $browser->request('GET', '/en/organizations/' . self::SLUG . '/results-import');
        self::assertResponseStatusCodeSame(404);
        $browser->request('POST', $this->pageUrl() . '/check');
        self::assertResponseStatusCodeSame(404);

        $browser->request('GET', '/en/event-actions/organization/' . OrganizationFixture::ORGANIZATION_RIVERBEND);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('/results-import', (string) $browser->getResponse()->getContent());
    }

    public function testTheMenuLinksThePageOnlyWhereASourceExists(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->browser->request('GET', '/en/event-actions/organization/' . OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT);
        self::assertResponseIsSuccessful();
        self::assertStringNotContainsString('/results-import', (string) $this->browser->getResponse()->getContent());

        // "You organize" lists the organization with the same actions
        $this->browser->request('GET', '/en/you-organize');
        self::assertResponseIsSuccessful();
        self::assertStringContainsString('/en/organizations/' . self::SLUG . '/results-import', (string) $this->browser->getResponse()->getContent());
    }

    public function testCheckingKeepsTheSheetAndListsEveryStatus(): void
    {
        $container = self::getContainer();
        $link = 'https://myspeedpuzzling.com/en/puzzle/' . PuzzleFixture::PUZZLE_500_01;

        $unchangedCsv = self::sheetCsv([
            "Puzzle Pals,1:10:13,4,,Maple Street Night,Maple Taproom,\"Springfield, MN\",10/5/26,7:00 PM,120,Teams,$link",
            'Edge Lords,1:12:04,3,,,,,,,,,',
        ]);
        self::applyTab($container, $this->source, '101', 'Maple 10/5', $unchangedCsv);

        $changedBefore = self::sheetCsv([
            "Corner Crew,0:50:00,4,,Corner Cafe Night,Corner Cafe,\"Springfield, MN\",10/7/26,6:30 PM,90,Teams,$link",
            'Gone Team,0:55:00,4,,,,,,,,,',
            'Kept Team,0:58:00,4,,,,,,,,,',
            'Slow Movers,1:01:00,4,,,,,,,,,',
        ]);
        self::applyTab($container, $this->source, '102', 'Corner 10/7', $changedBefore);

        // Somebody claims "Kept Team" - the import keeps it once it leaves the sheet (P4)
        $keptTimeId = $container->get(Connection::class)->fetchOne("SELECT pst.id FROM puzzle_solving_time pst JOIN puzzling_team pt ON pt.id = pst.puzzling_team_id WHERE pt.name = 'Kept Team'");
        self::assertIsString($keptTimeId);
        $container->get(MessageBusInterface::class)->dispatch(new ClaimEventResultSlot($keptTimeId, PlayerFixture::PLAYER_REGULAR));

        $this->fakeSheetTabs = [
            '101' => ['title' => 'Maple 10/5', 'csv' => $unchangedCsv],
            '102' => ['title' => 'Corner 10/7', 'csv' => self::sheetCsv([
                "Corner Crew,0:49:30,4,,Corner Cafe Night,Corner Cafe,\"Springfield, MN\",10/7/26,7:00 PM,90,Teams,$link",
                'Slow Movers,1:01:00,4,,,,,,,,,',
                'Fresh Faces,1:20:00,3,,,,,,,,,',
            ])],
            '103' => ['title' => 'Elm 10/9', 'csv' => self::sheetCsv([
                "Two Of Us,0:31:00,2,,Elm Pairs,Elm Library,\"Springfield, MN\",10/9/26,,60,Pairs,$link",
                'Swift Pair,0:29:00,2,,,,,,,,,',
                'Late Pair,,2,320,,,,,,,,',
                'Pair Twice,0:40:00,2,,,,,,,,,',
                'Pair Twice,0:41:00,2,,,,,,,,,',
                'Overtime Pair,1:05:00,2,,,,,,,,,',
            ])],
            '104' => ['title' => 'Oak 10/11', 'csv' => self::sheetCsv([
                'Oak Team,0:45:00,4,,Oak Night,Oak Hall,"Springfield, MN",10/11/26,,,Teams,',
            ])],
            '105' => ['title' => 'Pine 10/13', 'csv' => self::sheetCsv([
                "Pine Team,0:45:00,4,,Pine Night,Pine Hall,\"Springfield, MN\",13/10/26,,,Teams,$link",
            ])],
            '106' => ['title' => 'Broken tab', 'csv' => null],
        ];

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $crawler = $this->browser->request('GET', $this->pageUrl());
        $token = $crawler->filter('[data-results-import-check] input[name="_token"]')->attr('value');

        $this->browser->request('POST', $this->pageUrl() . '/check', ['_token' => $token]);
        self::assertResponseRedirects($this->pageUrl());

        // The sheet as read, every tab's CSV - never parsed events
        $stored = $container->get(Connection::class)->fetchAssociative('SELECT snapshot, read_error, checked_by_id FROM results_import_check WHERE source_id = :id', ['id' => $this->source->id->toString()]);
        self::assertIsArray($stored);
        self::assertNull($stored['read_error']);
        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES, $stored['checked_by_id']);
        self::assertIsString($stored['snapshot']);
        self::assertStringContainsString('Fresh Faces', $stored['snapshot']);

        $crawler = $this->browser->followRedirect();
        self::assertResponseIsSuccessful();

        $statuses = $crawler->filter('[data-results-import-event]')->each(static fn (Crawler $row): array => [
            $row->attr('data-results-import-event'),
            $row->filter('[data-results-import-status]')->attr('data-results-import-status'),
        ]);

        self::assertSame([
            ['101', 'unchanged'],
            ['102', 'changed'],
            ['103', 'new'],
            ['104', 'waiting_for_puzzle'],
            ['105', 'needs_fixing'],
        ], $statuses);

        $content = (string) $this->browser->getResponse()->getContent();
        // Plain words with the value the organizer wrote
        self::assertStringContainsString('We cannot read the date “13/10/26”', $content);
        // Google did not give one tab
        self::assertSelectorTextContains('[data-results-import-unreadable]', 'Broken tab');
        self::assertSelectorTextContains('[data-results-import-checked-at]', 'Michael Johnson');

        // The preview of the changed event: what changes, the claimed team kept (P4)
        $this->browser->request('GET', $this->pageUrl() . '/events/102');
        self::assertResponseIsSuccessful();
        $preview = $this->browser->getCrawler();
        self::assertSame(['changed', 'added', 'removed', 'kept_claimed'], $preview->filter('[data-results-import-team-change]')->each(static fn ($node): string => (string) $node->attr('data-results-import-team-change')));
        self::assertStringContainsString('No longer in the sheet - kept, claimed', $preview->filter('[data-results-import-team-change="kept_claimed"]')->text());
        self::assertStringContainsString('Gone Team', $preview->filter('[data-results-import-team-change="removed"]')->text());
        self::assertSame(['start_time'], $preview->filter('[data-results-import-event-change]')->each(static fn ($node): string => (string) $node->attr('data-results-import-event-change')));
        self::assertSelectorTextContains('[data-results-import-event-change="start_time"]', '18:30');
        self::assertSelectorTextContains('[data-results-import-event-change="start_time"]', '19:00');
        // The wall clock as written, never shifted by a zone
        self::assertSelectorTextContains('[data-results-import-start]', '19:00');
        self::assertSelectorExists('[data-results-import-stored-links]');
        // The claimed team the sheet dropped stays on the round page - ranked in the preview too, marked
        self::assertSame(['1', '2', '3', '4'], array_map('trim', $preview->filter('[data-results-import-rank]')->each(static fn ($node): string => $node->text())));
        self::assertStringContainsString('Kept Team', $preview->filter('[data-results-import-kept]')->closest('tr')?->text() ?? '');

        // The new pairs event: ranked like its round page - places, the unfinished pair, the time over the limit last
        $this->browser->request('GET', $this->pageUrl() . '/events/103');
        self::assertResponseIsSuccessful();
        $preview = $this->browser->getCrawler();
        self::assertSame(['1', '2', '', ''], array_map('trim', $preview->filter('[data-results-import-rank]')->each(static fn ($node): string => $node->text())));
        self::assertSame(['finished', 'finished', 'unfinished', 'over_limit'], $preview->filter('[data-results-import-row]')->each(static fn ($node): string => (string) $node->attr('data-results-import-row')));
        self::assertStringContainsString('Swift Pair', $preview->filter('[data-results-import-row]')->first()->text());
        self::assertStringContainsString('320 / 500', $preview->filter('[data-results-import-row="unfinished"]')->text());
        self::assertSelectorTextContains('[data-results-import-start]', 'Not given');
        $warnings = $preview->filter('[data-results-import-warnings]')->text();
        self::assertStringContainsString('“Pair Twice” is in the sheet twice', $warnings);
        self::assertStringContainsString('“Overtime Pair” has a time over the time limit', $warnings);
        self::assertStringContainsString('The times are not in order from “Swift Pair” on', $warnings);
        self::assertSelectorNotExists('[data-results-import-differences]');

        $this->browser->request('GET', $this->pageUrl() . '/events/999');
        self::assertResponseStatusCodeSame(404);
    }

    public function testASheetGoogleDoesNotShareIsSaidOnThePage(): void
    {
        $this->fakeSheetShared = false;
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $this->browser->request('GET', $this->pageUrl());
        $token = $crawler->filter('[data-results-import-check] input[name="_token"]')->attr('value');

        $this->browser->request('POST', $this->pageUrl() . '/check', ['_token' => $token]);
        self::assertResponseRedirects($this->pageUrl());
        $this->browser->followRedirect();

        self::assertSelectorTextContains('[data-results-import-read-error]', 'We could not read your sheet.');
    }

    public function testAStaleTokenRedirectsWithoutReading(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $this->browser->request('POST', $this->pageUrl() . '/check', ['_token' => 'nope']);

        self::assertResponseRedirects($this->pageUrl());
        self::assertSame(0, self::getContainer()->get(Connection::class)->fetchOne('SELECT COUNT(*) FROM results_import_check'));
    }
}
