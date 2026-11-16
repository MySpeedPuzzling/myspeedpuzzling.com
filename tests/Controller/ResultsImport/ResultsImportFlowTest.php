<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\ResultsImport;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\EventResultsSheet;
use SpeedPuzzling\Web\Entity\ResultsImportLog;
use SpeedPuzzling\Web\Message\ClaimEventResultSlot;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\ResultsImportScenario;
use SpeedPuzzling\Web\Tests\TestClock;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\EventResultsImport\ResultsImportOutcome;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * Importing from the results import page (docs/features/event-results-import/results-import-page.md "As built: PR 3"):
 * New and Changed ticked by default, a confirmation, the import of the ticked events from the stored check (Google is
 * not read again), the result with links to each event's results, the log and its history, a second submit importing
 * nothing twice, P4 (a team gone from the sheet removed unless claimed), and the refusals (an older check, nothing
 * ticked, a stale token). Made-up events and teams.
 */
final class ResultsImportFlowTest extends WebTestCase
{
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

    private function pageUrl(): string
    {
        return '/en/organizations/' . self::SLUG . '/results-import/' . $this->source->id->toString();
    }

    private function checkSheet(): Crawler
    {
        $crawler = $this->browser->request('GET', $this->pageUrl());
        $token = $crawler->filter('[data-results-import-check] input[name="_token"]')->attr('value');
        $this->browser->request('POST', $this->pageUrl() . '/check', ['_token' => $token]);
        self::assertResponseRedirects($this->pageUrl());

        return $this->browser->followRedirect();
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    public function testTickedEventsAreImportedFromTheCheckOnceWithTheirResultsLinked(): void
    {
        $container = self::getContainer();
        $link = 'https://myspeedpuzzling.com/en/puzzle/' . PuzzleFixture::PUZZLE_500_01;

        // Imported before: two of its teams leave the sheet - one nobody claimed, one somebody did (P4)
        self::applyTab($container, $this->source, '102', 'Corner 10/7', self::sheetCsv([
            "Corner Crew,0:50:00,4,,Corner Cafe Night,Corner Cafe,\"Springfield, MN\",10/7/26,6:30 PM,90,Teams,$link",
            'Gone Team,0:55:00,4,,,,,,,,,',
            'Kept Team,0:58:00,4,,,,,,,,,',
        ]));
        $keptTimeId = $this->connection()->fetchOne("SELECT pst.id FROM puzzle_solving_time pst JOIN puzzling_team pt ON pt.id = pst.puzzling_team_id WHERE pt.name = 'Kept Team'");
        self::assertIsString($keptTimeId);
        $container->get(MessageBusInterface::class)->dispatch(new ClaimEventResultSlot($keptTimeId, PlayerFixture::PLAYER_REGULAR));

        $this->fakeSheetTabs = [
            '102' => ['title' => 'Corner 10/7', 'csv' => self::sheetCsv([
                "Corner Crew,0:49:30,4,,Corner Cafe Night,Corner Cafe,\"Springfield, MN\",10/7/26,6:30 PM,90,Teams,$link",
                'Fresh Faces,1:20:00,3,,,,,,,,,',
            ])],
            '103' => ['title' => 'Elm 10/9', 'csv' => self::sheetCsv([
                "Swift Pair,0:29:00,2,,Elm Pairs,Elm Library,\"Springfield, MN\",10/9/26,7:00 PM,60,Pairs,$link",
                'Two Of Us,0:31:00,2,,,,,,,,,',
                'Late Pair,,2,320,,,,,,,,',
            ])],
            '104' => ['title' => 'Oak 10/11', 'csv' => self::sheetCsv([
                'Oak Team,0:45:00,4,,Oak Night,Oak Hall,"Springfield, MN",10/11/26,,,Teams,',
            ])],
        ];

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_FAVORITES);
        $crawler = $this->checkSheet();

        // New and Changed ticked, Waiting not tickable
        self::assertSame(['102', '103'], $crawler->filter('[data-results-import-tick]:checked')->each(static fn (Crawler $box): string => (string) $box->attr('value')));
        self::assertCount(1, $crawler->filter('[data-results-import-event="104"] input[disabled]'));

        // The preview ranks the claimed team the sheet dropped too - it stays on the round page (P4)
        $preview = $this->browser->request('GET', $this->pageUrl() . '/events/102');
        $previewRanks = array_map('trim', $preview->filter('[data-results-import-rank]')->each(static fn (Crawler $cell): string => $cell->text()));
        self::assertSame(['1', '2', '3'], $previewRanks);
        self::assertStringContainsString('Kept Team', $preview->filter('[data-results-import-kept]')->closest('tr')?->text() ?? '');

        // What Google says from now on is never imported - the stored check is
        $this->fakeSheetShared = false;
        $crawler = $this->browser->request('GET', $this->pageUrl());

        $form = $crawler->filter('[data-results-import-form]')->form();
        $confirm = $this->browser->submit($form);
        self::assertResponseIsSuccessful();
        self::assertSelectorTextContains('[data-results-import-confirm-heading]', 'Import 2 events, 5 teams?');
        self::assertSelectorExists('[data-results-import-no-undo]');

        $confirmForm = $confirm->filter('[data-results-import-confirm-form]')->form();
        $this->browser->submit($confirmForm);
        $location = (string) $this->browser->getResponse()->headers->get('Location');
        self::assertStringContainsString('?import=', $location);
        self::assertStringEndsWith('#import-result', $location);

        $result = $this->browser->followRedirect();
        self::assertSame(['imported', 'imported'], $result->filter('[data-results-import-result-event]')->each(static fn (Crawler $row): string => (string) $row->attr('data-outcome')));
        self::assertCount(2, $result->filter('[data-results-import-results-link]'));
        self::assertStringContainsString('Teams - new: 1, changed: 1, removed: 1', $result->filter('[data-results-import-result-event="102"]')->text());
        self::assertStringContainsString('Teams - new: 3, changed: 0, removed: 0', $result->filter('[data-results-import-result-event="103"]')->text());

        // The results page the link leads to
        $resultsLink = $result->filter('[data-results-import-result-event="103"] [data-results-import-results-link]')->attr('href');
        self::assertIsString($resultsLink);
        $this->browser->request('GET', $resultsLink);
        self::assertResponseIsSuccessful();
        self::assertSame(['1', '2'], array_values(array_filter(array_map('trim', $this->browser->getCrawler()->filter('[data-round-result-rank]')->each(static fn (Crawler $cell): string => $cell->text())))));

        // The round page of the changed event ranks what the preview ranked
        $changedResults = $result->filter('[data-results-import-result-event="102"] [data-results-import-results-link]')->attr('href');
        self::assertIsString($changedResults);
        $this->browser->request('GET', $changedResults);
        self::assertSame($previewRanks, array_map('trim', $this->browser->getCrawler()->filter('[data-round-result-rank]')->each(static fn (Crawler $cell): string => $cell->text())));

        // P4: the unclaimed team is gone, the claimed one kept; the claim untouched
        $teams = $this->connection()->fetchFirstColumn("SELECT pt.name FROM puzzle_solving_time pst JOIN puzzling_team pt ON pt.id = pst.puzzling_team_id JOIN competition c ON c.id = pst.competition_id WHERE c.name = 'Corner Cafe Night' ORDER BY pt.name");
        self::assertSame(['Corner Crew', 'Fresh Faces', 'Kept Team'], $teams);
        self::assertSame(PlayerFixture::PLAYER_REGULAR, $this->connection()->fetchOne('SELECT player_id FROM puzzle_solving_time WHERE id = :id', ['id' => $keptTimeId]));

        // The log: who, which events, the counts
        $log = $this->connection()->fetchAssociative('SELECT imported_by_id, teams_added, teams_updated, teams_removed, events FROM results_import_log');
        self::assertIsArray($log);
        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES, $log['imported_by_id']);
        self::assertSame([4, 1, 1], [$log['teams_added'], $log['teams_updated'], $log['teams_removed']]);
        self::assertIsString($log['events']);
        self::assertStringContainsString('Elm 10/9', $log['events']);

        // A second submit of the same confirmation imports nothing twice and shows the same result
        $timesBefore = $this->connection()->fetchOne('SELECT COUNT(*) FROM puzzle_solving_time');
        $this->browser->submit($confirmForm);
        self::assertSame($location, $this->browser->getResponse()->headers->get('Location'));
        self::assertEquals(1, $this->connection()->fetchOne('SELECT COUNT(*) FROM results_import_log'));
        self::assertSame($timesBefore, $this->connection()->fetchOne('SELECT COUNT(*) FROM puzzle_solving_time'));

        // Back on the page: the imported events are unchanged now, nothing ticked; the history lists the import
        $page = $this->browser->request('GET', $this->pageUrl());
        self::assertSame('unchanged', $page->filter('[data-results-import-event="103"] [data-results-import-status]')->attr('data-results-import-status'));
        self::assertCount(0, $page->filter('[data-results-import-tick]'));
        self::assertSelectorExists('[data-results-import-nothing]');
        self::assertSelectorTextContains('[data-results-import-history]', 'Michael Johnson');
        self::assertSelectorTextContains('[data-results-import-history]', '2 events imported');
    }

    public function testOnlyTheLatestCheckIsImportedFrom(): void
    {
        $link = 'https://myspeedpuzzling.com/en/puzzle/' . PuzzleFixture::PUZZLE_500_01;
        $this->fakeSheetTabs = ['201' => ['title' => 'Birch', 'csv' => self::sheetCsv([
            "Birch Team,0:45:00,4,,Birch Night,Birch Hall,\"Springfield, MN\",10/11/26,,,Teams,$link",
        ])]];

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $this->checkSheet();
        $form = $crawler->filter('[data-results-import-form]')->form();
        $confirm = $this->browser->submit($form);
        $confirmForm = $confirm->filter('[data-results-import-confirm-form]')->form();

        // Somebody checks again meanwhile
        $this->checkSheet();

        $this->browser->submit($form);
        self::assertResponseRedirects($this->pageUrl());
        $this->browser->followRedirect();
        self::assertSelectorTextContains('.alert, [role="alert"], body', 'Your sheet was checked again meanwhile');

        $this->browser->submit($confirmForm);
        self::assertResponseRedirects($this->pageUrl());
        self::assertEquals(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM results_import_log'));
        self::assertEquals(0, $this->connection()->fetchOne("SELECT COUNT(*) FROM competition WHERE name = 'Birch Night'"));
    }

    public function testNothingTickedOrAStaleTokenImportsNothing(): void
    {
        $link = 'https://myspeedpuzzling.com/en/puzzle/' . PuzzleFixture::PUZZLE_500_01;
        $this->fakeSheetTabs = ['301' => ['title' => 'Cedar', 'csv' => self::sheetCsv([
            "Cedar Team,0:45:00,4,,Cedar Night,Cedar Hall,\"Springfield, MN\",10/11/26,,,Teams,$link",
        ])]];

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $this->checkSheet();
        $checkId = $crawler->filter('[data-results-import-form] input[name="check"]')->attr('value');

        $this->browser->request('GET', $this->pageUrl() . '/import', ['check' => $checkId]);
        self::assertResponseRedirects($this->pageUrl());
        $this->browser->followRedirect();
        self::assertSelectorTextContains('body', 'Tick at least one event to import.');

        $this->browser->request('POST', $this->pageUrl() . '/import', ['_token' => 'nope', 'import' => '0196f5a0-0000-7000-8000-000000000001', 'check' => $checkId, 'events' => ['301']]);
        self::assertResponseRedirects($this->pageUrl());
        self::assertEquals(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM results_import_log'));
    }

    public function testAnotherPlayerCannotImport(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->browser->request('GET', $this->pageUrl() . '/import');
        self::assertResponseStatusCodeSame(403);
        $this->browser->request('POST', $this->pageUrl() . '/import');
        self::assertResponseStatusCodeSame(403);
    }

    public function testAGuestIsAskedToSignIn(): void
    {
        foreach (['GET', 'POST'] as $method) {
            $this->browser->request($method, $this->pageUrl() . '/import');
            self::assertResponseRedirects();
            self::assertStringContainsString('/login', (string) $this->browser->getResponse()->headers->get('Location'));
        }
    }

    public function testEveryLocaleRendersTheConfirmationAndEveryOutcome(): void
    {
        $link = 'https://myspeedpuzzling.com/en/puzzle/' . PuzzleFixture::PUZZLE_500_01;
        $this->fakeSheetTabs = ['901' => ['title' => 'Locale', 'csv' => self::sheetCsv([
            "Locale Team,0:45:00,4,,Locale Night,Hall,\"Springfield, MN\",10/11/26,,,Teams,$link",
        ])]];

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $crawler = $this->checkSheet();
        $checkId = (string) $crawler->filter('[data-results-import-form] input[name="check"]')->attr('value');

        // Every outcome an import can log, one event each
        $importId = Uuid::uuid7();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $source = $entityManager->find(EventResultsSheet::class, $this->source->id);
        self::assertNotNull($source);
        $events = [];

        foreach (ResultsImportOutcome::cases() as $i => $outcome) {
            $events[] = ['key' => (string) (950 + $i), 'title' => 'Outcome ' . $outcome->value, 'outcome' => $outcome->value, 'competition_id' => null, 'added' => 1, 'updated' => 0, 'removed' => 0];
        }

        $entityManager->persist(new ResultsImportLog($importId, $source, Uuid::fromString($checkId), null, TestClock::now(), $events, 6, 0, 0));
        $entityManager->flush();

        foreach (['cs', 'de', 'es', 'fr', 'ja', 'en'] as $locale) {
            $base = '/' . $locale . '/organizations/' . self::SLUG . '/results-import/' . $this->source->id->toString();
            $this->browser->request('GET', $base . '/import', ['check' => $checkId, 'events' => ['901']]);
            self::assertResponseIsSuccessful($locale);
            self::assertSelectorExists('[data-results-import-confirm-form]');

            $page = $this->browser->request('GET', $base, ['import' => $importId->toString()]);
            self::assertResponseIsSuccessful($locale);
            self::assertSame(
                array_map(static fn (ResultsImportOutcome $outcome): string => $outcome->value, ResultsImportOutcome::cases()),
                $page->filter('[data-results-import-result-event]')->each(static fn (Crawler $row): string => (string) $row->attr('data-outcome')),
            );
        }

        // Nobody's name for an account gone; every outcome in plain words
        self::assertSelectorTextContains('[data-results-import-result]', 'Import done');
        self::assertStringContainsString('somebody imported a newer check of it meanwhile', (string) $this->browser->getResponse()->getContent());
    }
}
