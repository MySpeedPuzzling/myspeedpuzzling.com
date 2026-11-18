<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Tests\CompetitionToolkit\Divisions\DivisionsSetup;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The organiser's side of divisions (shared-puzzles-and-divisions.md D1 + D2): the hub card, the divisions page (save,
 * refusal, "in use" confirmation), and what a round with divisions adds to the round state, the results desk and the
 * export - a round without them stays exactly as it was (RoundResultsStateGoldenTest, PickRoundDeskAndExportTest).
 */
final class CompetitionDivisionsPagesTest extends WebTestCase
{
    use DivisionsSetup;

    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string PAGE = '/en/manage-event-toolkit/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP . '/divisions';

    private KernelBrowser $browser;
    private Connection $database;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->database = self::getContainer()->get(Connection::class);
    }

    public function testOnlyTheEventsOrganisersMayManageDivisions(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);
        $this->browser->request('GET', self::PAGE);
        self::assertResponseStatusCodeSame(403);
    }

    public function testTheHubLinksTheDivisionsPage(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $hub = $this->browser->request('GET', '/en/manage-event-toolkit/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $hub->filter('[data-toolkit-card="divisions"] [data-toolkit-divisions-empty]'));
        self::assertSame(self::PAGE, $hub->filter('[data-toolkit-divisions-link]')->attr('href'));
    }

    public function testTheOrganiserSavesTheListThenTicksTheRounds(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $page = $this->browser->request('GET', self::PAGE);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-divisions-grid-empty]'));

        $form = $page->filter('[data-divisions-save]')->form();
        $form['divisions[0][name]'] = 'Regular';
        $form['divisions[1][name]'] = 'Senior 55+';
        $form['divisions[1][shortName]'] = 'S';
        $this->browser->submit($form);
        self::assertResponseStatusCodeSame(303);

        $page = $this->browser->followRedirect();
        self::assertSame(['Regular', 'Senior 55+'], $page->filter('[data-divisions-grid] thead th')->slice(1)->each(static fn (Crawler $th): string => trim($th->text())));

        $form = $page->filter('[data-divisions-save]')->form();
        $regular = $this->database->fetchOne("SELECT id FROM competition_division WHERE name = 'Regular'");
        $senior = $this->database->fetchOne("SELECT id FROM competition_division WHERE name = 'Senior 55+'");
        self::assertIsString($regular);
        self::assertIsString($senior);
        /** @var \Symfony\Component\DomCrawler\Field\ChoiceFormField[] $groupA */
        $groupA = $form['rounds[' . OfficialResultsFixture::ROUND_GROUP_A . ']'];
        foreach ($groupA as $checkbox) {
            $checkbox->tick();
        }
        $this->browser->submit($form);
        self::assertResponseStatusCodeSame(303);

        $roundDivisions = $this->database->fetchFirstColumn('SELECT division_id FROM competition_round_division WHERE round_id = ? ORDER BY division_id', [OfficialResultsFixture::ROUND_GROUP_A]);
        $expected = [$regular, $senior];
        sort($expected);
        self::assertSame($expected, $roundDivisions);
    }

    public function testADuplicateNameIsRefusedWith422(): void
    {
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
        $form = $this->browser->request('GET', self::PAGE)->filter('[data-divisions-save]')->form();
        $form['divisions[0][name]'] = 'Senior';
        $form['divisions[1][name]'] = 'senior';
        $page = $this->browser->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('senior', $page->filter('[data-divisions-error]')->text());
        self::assertSame(0, self::divisionRowCount($this->database, 'SELECT COUNT(*) FROM competition_division'));
    }

    public function testTakingADivisionFromEntriesAsksFirst(): void
    {
        $this->setUpStPaulDivisions(self::getContainer()->get(MessageBusInterface::class));
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $form = $this->browser->request('GET', self::PAGE)->filter('[data-divisions-save]')->form();
        // Empty the Senior row - Ben, Cara and Dan carry it
        $form['divisions[1][name]'] = '';
        $page = $this->browser->submit($form);

        self::assertResponseStatusCodeSame(422);
        self::assertStringContainsString('3 entries would lose their division', $page->filter('[data-divisions-in-use]')->text());
        self::assertSame(3, self::divisionRowCount($this->database, 'SELECT COUNT(*) FROM competition_division'));

        $this->browser->submit($page->filter('[data-divisions-confirm]')->form());
        self::assertResponseStatusCodeSame(303);
        self::assertSame(['Regular', 'Junior U16'], $this->database->fetchFirstColumn('SELECT name FROM competition_division ORDER BY position'));
        self::assertSame(0, self::divisionRowCount($this->database, 'SELECT COUNT(*) FROM competition_participant_round WHERE division_id IS NOT NULL AND division_id NOT IN (SELECT id FROM competition_division)'));
    }

    public function testARoundWithDivisionsCarriesThemInTheStateTheDeskAndTheExport(): void
    {
        $this->setUpStPaulDivisions(self::getContainer()->get(MessageBusInterface::class));
        TestingLogin::asPlayer($this->browser, self::ORGANISER);

        $this->browser->request('GET', '/en/official-results/rounds/' . OfficialResultsFixture::ROUND_GROUP_A);
        self::assertResponseIsSuccessful();
        /** @var array{round: array{divisions: list<array{name: string, shortName: null|string}>}, rounds: list<array<string, mixed>>, entries: list<array{displayName: string, rank: null|int, division: null|array{name: string}, divisionRank: null|int}>} $state */
        $state = json_decode((string) $this->browser->getResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame(['Regular', 'Senior 55+', 'Junior U16'], array_column($state['round']['divisions'], 'name'));
        self::assertSame(['Anna Fast' => [1, 'Regular', 1], 'Ben Steady' => [2, 'Senior 55+', 1], 'Cara Tied' => [2, 'Senior 55+', 1], 'Dan Unfinished' => [4, 'Senior 55+', 3], 'Eva Noshow' => [null, 'Junior U16', null], 'Filip Pending' => [null, null, null]], array_combine(
            array_column($state['entries'], 'displayName'),
            array_map(static fn (array $entry): array => [$entry['rank'], $entry['division']['name'] ?? null, $entry['divisionRank']], $state['entries']),
        ));
        // Group B has no divisions: nothing about them anywhere in its overview
        $groupB = array_values(array_filter($state['rounds'], static fn (array $round): bool => $round['id'] === OfficialResultsFixture::ROUND_GROUP_B))[0] ?? null;
        self::assertIsArray($groupB);
        self::assertArrayNotHasKey('divisions', $groupB);

        // The desk: the column, the chips' texts
        $desk = $this->browser->request('GET', '/en/manage-round-results/' . OfficialResultsFixture::ROUND_GROUP_A);
        self::assertResponseIsSuccessful();
        self::assertCount(1, $desk->filter('[data-results-desk-target="divisionColumn"]'));
        self::assertCount(1, $desk->filter('[data-results-desk-target="divisionFilter"]'));
        $texts = json_decode((string) $desk->filter('[data-controller="results-desk"]')->attr('data-results-desk-texts-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($texts);
        self::assertSame('No division', $texts['division_none'] ?? null);

        // ... and a round without divisions has none of it
        $desk = $this->browser->request('GET', '/en/manage-round-results/' . OfficialResultsFixture::ROUND_GROUP_B);
        self::assertCount(0, $desk->filter('[data-results-desk-target="divisionColumn"], [data-results-desk-target="divisionFilter"]'));
        $texts = json_decode((string) $desk->filter('[data-controller="results-desk"]')->attr('data-results-desk-texts-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($texts);
        self::assertArrayNotHasKey('division_none', $texts);

        // The export: "Division" + "Division rank" right after "Rank"
        $this->browser->request('GET', '/en/manage-round-results/' . OfficialResultsFixture::ROUND_GROUP_A . '/export/csv');
        self::assertResponseIsSuccessful();
        $rows = self::csvRows((string) $this->browser->getResponse()->getContent());
        self::assertSame(['Rank', 'Division', 'Division rank', 'Table'], array_slice($rows[0], 0, 4));
        $dan = array_values(array_filter($rows, static fn (array $row): bool => ($row[4] ?? null) === 'Dan Unfinished'))[0] ?? null;
        self::assertSame(['4', 'Senior 55+', '3'], array_slice((array) $dan, 0, 3));

        $this->browser->request('GET', '/en/manage-round-results/' . OfficialResultsFixture::ROUND_GROUP_B . '/export/csv');
        self::assertSame(['Rank', 'Table'], array_slice(self::csvRows((string) $this->browser->getResponse()->getContent())[0], 0, 2));
    }

    /**
     * @return list<list<string>>
     */
    private static function csvRows(string $content): array
    {
        $content = (string) preg_replace('/^\xEF\xBB\xBF/', '', $content);

        $rows = [];
        foreach (explode("\n", trim($content)) as $line) {
            $rows[] = str_getcsv($line, ',', '"', '');
        }

        /** @var list<list<string>> $rows */
        return $rows;
    }
}
