<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\ResultsImport;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Entity\EventResultsSheet;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\ResultsImportScenario;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * "Change sheet" on the results import page (docs/features/event-results-import/results-import-page.md "As built:
 * PR 4"): the organization's team points the source at another spreadsheet - read once (Google faked), saved with
 * that read as the only check, its events shown against what was imported from the previous sheet; refused (nothing
 * changed, no check written) for a link that is no sheet, a published link, the same sheet, a sheet another source
 * reads, a sheet Google does not give us and a stale token. Made-up spreadsheet ids, events and teams.
 */
final class ResultsSourceSheetChangeTest extends WebTestCase
{
    use ResultsImportScenario;

    private const string SLUG = OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG;
    private const string OLD_SHEET = 'test-spreadsheet-results-import-0123';
    private const string NEW_SHEET = 'new-test-spreadsheet-0123456789';

    private KernelBrowser $browser;

    private EventResultsSheet $source;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->browser->disableReboot();
        $this->source = $this->createResultsSource(self::getContainer(), self::OLD_SHEET);
        $this->fakeGoogle(self::getContainer());
    }

    private function pageUrl(string $locale = 'en'): string
    {
        return '/' . $locale . '/organizations/' . self::SLUG . '/results-import/' . $this->source->id->toString();
    }

    private function changeSheet(string $pasted, null|string $token = null): void
    {
        if ($token === null) {
            $crawler = $this->browser->request('GET', $this->pageUrl());
            $token = (string) $crawler->filter('[data-results-import-sheet-form] input[name="_token"]')->attr('value');
        }

        $this->browser->request('POST', $this->pageUrl() . '/sheet', ['_token' => $token, 'spreadsheet' => $pasted]);
    }

    private function connection(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private function storedSpreadsheetId(): mixed
    {
        return $this->connection()->fetchOne('SELECT spreadsheet_id FROM event_results_sheet WHERE id = :id', ['id' => $this->source->id->toString()]);
    }

    public function testTheTeamChangesTheSheetAndSeesItsEventsAgainstTheImportedOnes(): void
    {
        $link = 'https://myspeedpuzzling.com/en/puzzle/' . PuzzleFixture::PUZZLE_500_01;
        $maple = static fn (string $time): string => self::sheetCsv([
            "Maple Team,$time,4,,Maple Night,Maple Hall,\"Springfield, MN\",10/5/26,7:00 PM,90,Teams,$link",
        ]);
        self::applyTab(self::getContainer(), $this->source, '101', 'Maple 10/5', $maple('1:10:00'));

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_FAVORITES);

        // The current sheet opens in Google; a check of it is kept
        $crawler = $this->browser->request('GET', $this->pageUrl());
        self::assertStringContainsString('/spreadsheets/d/' . self::OLD_SHEET . '/', (string) $crawler->filter('[data-results-import-sheet-link]')->attr('href'));
        $this->fakeSheetTabs = ['101' => ['title' => 'Maple 10/5', 'csv' => $maple('1:10:00')]];
        $token = $crawler->filter('[data-results-import-check] input[name="_token"]')->attr('value');
        $this->browser->request('POST', $this->pageUrl() . '/check', ['_token' => $token]);
        self::assertEquals(1, $this->connection()->fetchOne('SELECT COUNT(*) FROM results_import_check'));

        // The new sheet: the same event under another gid, its time corrected
        $this->fakeSheetTabs = ['901' => ['title' => 'Maple', 'csv' => $maple('1:09:00')]];
        $this->changeSheet('https://docs.google.com/spreadsheets/d/' . self::NEW_SHEET . '/edit#gid=901');
        self::assertResponseRedirects($this->pageUrl());

        $page = $this->browser->followRedirect();
        self::assertSelectorTextContains('body', 'Your sheet is changed');
        self::assertStringContainsString('/spreadsheets/d/' . self::NEW_SHEET . '/', (string) $page->filter('[data-results-import-sheet-link]')->attr('href'));
        self::assertSame(self::NEW_SHEET, $this->storedSpreadsheetId());

        // The read is the only check - who changed it and when
        $check = $this->connection()->fetchAllAssociative('SELECT checked_by_id, read_error FROM results_import_check');
        self::assertCount(1, $check);
        self::assertSame(PlayerFixture::PLAYER_WITH_FAVORITES, $check[0]['checked_by_id']);
        self::assertNull($check[0]['read_error']);

        // Against the event imported from the previous sheet - changed, not new
        self::assertSame('changed', $page->filter('[data-results-import-event="901"] [data-results-import-status]')->attr('data-results-import-status'));
        self::assertCount(1, $page->filter('[data-results-import-event="901"] [data-results-import-previous-sheet]'));

        $this->browser->request('GET', $this->pageUrl() . '/events/901');
        self::assertResponseIsSuccessful();
        self::assertSelectorExists('[data-results-import-previous-sheet]');

        // Ticked, confirmed (the note again) and imported: the same event, its item under the new key
        $confirm = $this->browser->submit($page->filter('[data-results-import-form]')->form());
        self::assertResponseIsSuccessful();
        self::assertCount(1, $confirm->filter('[data-results-import-confirm-event="901"] [data-results-import-previous-sheet]'));
        $this->browser->submit($confirm->filter('[data-results-import-confirm-form]')->form());
        $result = $this->browser->followRedirect();
        self::assertSame(['imported'], $result->filter('[data-results-import-result-event]')->each(static fn (Crawler $row): string => (string) $row->attr('data-outcome')));

        self::assertEquals(1, $this->connection()->fetchOne("SELECT COUNT(*) FROM competition WHERE name = 'Maple Night'"));
        self::assertEquals(4140, $this->connection()->fetchOne("SELECT pst.seconds_to_solve FROM puzzle_solving_time pst JOIN competition c ON c.id = pst.competition_id WHERE c.name = 'Maple Night'"));
        self::assertSame(['901'], $this->connection()->fetchFirstColumn('SELECT gid FROM event_results_sheet_tab WHERE sheet_id = :id', ['id' => $this->source->id->toString()]));
    }

    public function testEveryRefusalChangesNothing(): void
    {
        $this->createResultsSource(self::getContainer(), 'other-test-spreadsheet-0123456789');
        $this->fakeSheetTabs = ['1' => ['title' => 'Sheet1', 'csv' => self::sheetCsv([])]];

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);
        $refused = $this->pageUrl() . '#your-sheet';

        $cases = [
            'This is not a link to a Google sheet' => 'https://example.com/spreadsheets/d/' . self::NEW_SHEET,
            'This is a “Publish to the web” link' => 'https://docs.google.com/spreadsheets/d/e/2PACX-1vTestPublishedLink0123456789/pubhtml',
            'This is your sheet already' => self::OLD_SHEET,
            'This sheet is used for another results import already' => 'https://docs.google.com/spreadsheets/d/other-test-spreadsheet-0123456789/edit',
        ];

        foreach ($cases as $flash => $pasted) {
            $this->changeSheet($pasted);
            self::assertResponseRedirects($refused, message: $flash);
            $this->browser->followRedirect();
            self::assertSelectorTextContains('body', $flash);
        }

        // Not shared with the link
        $this->fakeSheetShared = false;
        $this->changeSheet(self::NEW_SHEET);
        self::assertResponseRedirects($refused);
        $this->browser->followRedirect();
        self::assertSelectorTextContains('body', 'We could not read this sheet');

        // A stale token
        $this->fakeSheetShared = true;
        $this->changeSheet(self::NEW_SHEET, 'stale');
        self::assertResponseRedirects($refused);

        self::assertSame(self::OLD_SHEET, $this->storedSpreadsheetId());
        self::assertEquals(0, $this->connection()->fetchOne('SELECT COUNT(*) FROM results_import_check'));
    }

    public function testOnlyTheTeamAndAdminsChangeTheSheet(): void
    {
        $this->browser->request('POST', $this->pageUrl() . '/sheet', ['spreadsheet' => self::NEW_SHEET]);
        self::assertResponseRedirects();
        self::assertStringContainsString('/login', (string) $this->browser->getResponse()->headers->get('Location'));

        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->browser->request('POST', $this->pageUrl() . '/sheet', ['spreadsheet' => self::NEW_SHEET]);
        self::assertResponseStatusCodeSame(403);

        self::assertSame(self::OLD_SHEET, $this->storedSpreadsheetId());
    }

    public function testEveryLocaleRendersYourSheet(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_ADMIN);

        foreach (['cs', 'de', 'es', 'fr', 'ja', 'en'] as $locale) {
            $this->browser->request('GET', $this->pageUrl($locale));
            self::assertResponseIsSuccessful($locale);
            self::assertSelectorExists('[data-results-import-sheet-form]');
            self::assertSelectorExists('[data-results-import-sheet-link]');
        }
    }
}
