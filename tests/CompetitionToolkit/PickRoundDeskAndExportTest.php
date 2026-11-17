<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\CompetitionToolkit;

use Doctrine\DBAL\Connection;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The organiser's desk-side tools on a `pick` round (competition toolkit A4, A-round-formats.md §2.6): Group B as
 * "pick 2 of 3" - SECRET (hidden, manual reveal), PUBLIC (Puzzle 14) and the fixture's puzzle. Ivan finished in
 * 4800 s with both splits recorded, Hugo has no record.
 *
 * - the results desk gets the per-puzzle editor's texts and the round's `puzzleFormat` + the entries' `puzzles`;
 * - the export adds "Puzzle N" columns (a hidden puzzle by its label only) in CSV and XLSX;
 * - the participants sheet's state carries `puzzleFormat` on the pick round and `puzzles` on its entries;
 * - every total round: the desk's texts, the export's columns and the sheet's JSON stay exactly what they were.
 */
final class PickRoundDeskAndExportTest extends WebTestCase
{
    use RoundPuzzleFormatSetup;

    private const string ORGANISER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const array TOTAL_COLUMNS = ['Rank', 'Table', 'Entrant', 'Members', 'Country', 'Result', 'Time (seconds)', 'Pieces placed', 'Qualified', 'Entered by', 'Entered at'];

    private KernelBrowser $browser;
    private Connection $database;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
        $this->database = self::getContainer()->get(Connection::class);
        self::addRoundPuzzles($this->database);
        self::makePick($this->database, 2);
        $this->database->executeStatement(
            'UPDATE competition_participant_round SET result_seconds = 4800, result_puzzles = :puzzles WHERE id = :id',
            [
                'puzzles' => json_encode([['roundPuzzleId' => self::PUBLIC, 'done' => true, 'atSeconds' => 2710], ['roundPuzzleId' => self::SECRET, 'done' => true, 'atSeconds' => 4800]], JSON_THROW_ON_ERROR),
                'id' => OfficialResultsFixture::ENTRY_B_IVAN,
            ],
        );
        TestingLogin::asPlayer($this->browser, self::ORGANISER);
    }

    public function testTheDeskOfAPickRoundGetsTheEditorAndTheRecords(): void
    {
        $crawler = $this->browser->request('GET', '/en/manage-round-results/' . self::ROUND);
        self::assertResponseIsSuccessful();

        $desk = $crawler->filter('[data-controller="results-desk"]');
        $texts = json_decode((string) $desk->attr('data-results-desk-texts-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($texts);
        self::assertSame('Each entrant picks %count% of %total% puzzles', $texts['puzzles_format'] ?? null);
        self::assertArrayHasKey('reason_puzzles_mismatch', $texts);

        /** @var array{round: array{puzzleFormat: array{mode: string, toSolve: int}}, entries: list<array{ref: string, puzzles: null|list<array{roundPuzzleId: string}>}>} $state */
        $state = json_decode((string) $desk->attr('data-results-desk-state-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertSame('pick', $state['round']['puzzleFormat']['mode']);
        self::assertSame(2, $state['round']['puzzleFormat']['toSolve']);

        $byRef = [];
        foreach ($state['entries'] as $entry) {
            $byRef[$entry['ref']] = $entry;
        }
        self::assertSame(self::PUBLIC, $byRef['participant_round:' . OfficialResultsFixture::ENTRY_B_IVAN]['puzzles'][0]['roundPuzzleId'] ?? null);
        self::assertNull($byRef['participant_round:' . OfficialResultsFixture::ENTRY_B_HUGO]['puzzles']);
        // The hidden puzzle's name is nowhere on the page (the public rule, organisers too)
        self::assertStringNotContainsString(self::SECRET_NAME, (string) $this->browser->getInternalResponse()->getContent());
    }

    public function testTheDeskOfATotalRoundGetsNoneOfIt(): void
    {
        $crawler = $this->browser->request('GET', '/en/manage-round-results/' . OfficialResultsFixture::ROUND_GROUP_A);
        self::assertResponseIsSuccessful();

        $desk = $crawler->filter('[data-controller="results-desk"]');
        $texts = json_decode((string) $desk->attr('data-results-desk-texts-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertIsArray($texts);
        self::assertSame([], array_values(array_filter(array_keys($texts), static fn (string $key): bool => str_starts_with($key, 'puzzles_') || str_starts_with($key, 'reason_'))));

        /** @var array{round: array<string, mixed>, entries: list<array<string, mixed>>} $state */
        $state = json_decode((string) $desk->attr('data-results-desk-state-value'), true, flags: JSON_THROW_ON_ERROR);
        self::assertArrayNotHasKey('puzzleFormat', $state['round']);
        self::assertArrayNotHasKey('puzzles', $state['entries'][0]);
    }

    public function testTheExportOfAPickRoundAddsPuzzleColumnsInBothFormats(): void
    {
        $this->browser->request('GET', '/en/manage-round-results/' . self::ROUND . '/export/csv');
        self::assertResponseIsSuccessful();
        $rows = self::csvRows((string) $this->browser->getInternalResponse()->getContent());

        self::assertSame([
            ...self::TOTAL_COLUMNS,
            'Puzzle 1', 'Puzzle 1 finished at', 'Puzzle 1 own time', 'Puzzle 1 pieces placed',
            'Puzzle 2', 'Puzzle 2 finished at', 'Puzzle 2 own time', 'Puzzle 2 pieces placed',
        ], $rows[0]);

        $ivan = array_values(array_filter($rows, static fn (array $row): bool => $row[2] === 'Ivan Last'))[0] ?? null;
        self::assertIsArray($ivan);
        self::assertStringEndsWith(' - ' . self::PUBLIC_NAME, $ivan[11]);
        self::assertSame(['0:45:10', '0:45:10', ''], array_slice($ivan, 12, 3));
        // The hidden puzzle: its label only
        self::assertStringNotContainsString(self::SECRET_NAME, $ivan[15]);
        self::assertNotSame('', $ivan[15]);
        self::assertSame(['1:20:00', '0:34:50', ''], array_slice($ivan, 16, 3));
        self::assertStringNotContainsString(self::SECRET_NAME, (string) $this->browser->getInternalResponse()->getContent());

        // Without a record: empty puzzle cells
        $hugo = array_values(array_filter($rows, static fn (array $row): bool => $row[2] === 'Hugo Slow'))[0] ?? null;
        self::assertIsArray($hugo);
        self::assertSame(array_fill(0, 8, ''), array_slice($hugo, 11));

        $this->browser->request('GET', '/en/manage-round-results/' . self::ROUND . '/export/xlsx');
        self::assertResponseIsSuccessful();
        $sheet = self::sheet((string) $this->browser->getInternalResponse()->getContent());
        self::assertSame('Puzzle 1', $sheet->getCell([12, 1])->getValue());
        self::assertSame('Puzzle 2 pieces placed', $sheet->getCell([19, 1])->getValue());
        self::assertNull($sheet->getCell([20, 1])->getValue());
    }

    public function testATotalRoundExportsExactlyTheColumnsItAlwaysDid(): void
    {
        $this->browser->request('GET', '/en/manage-round-results/' . OfficialResultsFixture::ROUND_GROUP_A . '/export/csv');
        $rows = self::csvRows((string) $this->browser->getInternalResponse()->getContent());

        self::assertSame(self::TOTAL_COLUMNS, $rows[0]);
        foreach ($rows as $row) {
            self::assertCount(11, $row);
        }

        $this->browser->request('GET', '/en/manage-round-results/' . OfficialResultsFixture::ROUND_GROUP_A . '/export/xlsx');
        $sheet = self::sheet((string) $this->browser->getInternalResponse()->getContent());
        self::assertSame('Entered at', $sheet->getCell([11, 1])->getValue());
        self::assertNull($sheet->getCell([12, 1])->getValue());
    }

    public function testTheSheetStateCarriesTheFormatAndRecordsOfThePickRoundOnly(): void
    {
        $this->browser->request('GET', '/en/participants-sheet-api/' . OfficialResultsFixture::COMPETITION_RESULTS_CUP . '/state');
        self::assertResponseIsSuccessful();
        /** @var array{rounds: list<array{id: string, puzzleFormat?: array{mode: string, puzzles: list<mixed>}}>, places: list<array{id: string, roundId: string, puzzles?: null|list<array{roundPuzzleId: string}>}>, teams: list<array<string, mixed>>} $state */
        $state = json_decode((string) $this->browser->getInternalResponse()->getContent(), true, flags: JSON_THROW_ON_ERROR);

        foreach ($state['rounds'] as $round) {
            if ($round['id'] === self::ROUND) {
                self::assertSame('pick', $round['puzzleFormat']['mode'] ?? null);
                self::assertCount(3, $round['puzzleFormat']['puzzles']);
            } else {
                self::assertArrayNotHasKey('puzzleFormat', $round);
            }
        }

        foreach ($state['places'] as $place) {
            if ($place['roundId'] !== self::ROUND) {
                self::assertArrayNotHasKey('puzzles', $place);
            } elseif ($place['id'] === OfficialResultsFixture::ENTRY_B_IVAN) {
                self::assertSame(self::SECRET, $place['puzzles'][1]['roundPuzzleId'] ?? null);
            } else {
                self::assertArrayHasKey('puzzles', $place);
                self::assertNull($place['puzzles']);
            }
        }

        foreach ($state['teams'] as $team) {
            self::assertArrayNotHasKey('puzzles', $team);
        }

        self::assertStringNotContainsString(self::SECRET_NAME, (string) $this->browser->getInternalResponse()->getContent());
    }

    /**
     * @return list<list<string>>
     */
    private static function csvRows(string $content): array
    {
        $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
        self::assertIsString($content);

        $rows = [];
        foreach (explode("\n", trim($content)) as $line) {
            $rows[] = str_getcsv($line, ',', '"', '');
        }

        /** @var list<list<string>> $rows */
        return $rows;
    }

    private static function sheet(string $content): Worksheet
    {
        $file = tempnam(sys_get_temp_dir(), 'xlsx_test_');
        self::assertIsString($file);

        try {
            file_put_contents($file, $content);
            $spreadsheet = IOFactory::createReader('Xlsx')->load($file);
        } finally {
            unlink($file);
        }

        return $spreadsheet->getActiveSheet();
    }
}
