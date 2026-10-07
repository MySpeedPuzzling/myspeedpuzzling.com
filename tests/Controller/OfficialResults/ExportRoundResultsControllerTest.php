<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller\OfficialResults;

use Doctrine\DBAL\Connection;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\IOFactory;
use SpeedPuzzling\Web\Tests\DataFixtures\OfficialResultsFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;

/**
 * The results desk's export of one round (RoundResultsExporter): organisers only, never cached, every entry in ranking
 * order, and no cell a spreadsheet would run as a formula.
 */
final class ExportRoundResultsControllerTest extends WebTestCase
{
    private const string GROUP_A = '/en/manage-round-results/' . OfficialResultsFixture::ROUND_GROUP_A . '/export/';

    private KernelBrowser $browser;

    protected function setUp(): void
    {
        $this->browser = self::createClient();
    }

    public function testNobodySignedInIsSentToSignIn(): void
    {
        $this->browser->request('GET', self::GROUP_A . 'csv');

        self::assertResponseRedirects();
    }

    public function testOnlyTheEventsOrganisersMayExport(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_REGULAR);

        $this->browser->request('GET', self::GROUP_A . 'csv');

        self::assertResponseStatusCodeSame(403);
    }

    public function testOnlyCsvAndXlsx(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->browser->request('GET', self::GROUP_A . 'json');

        self::assertResponseStatusCodeSame(404);
    }

    public function testCsvHasEveryEntryInRankingOrder(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->browser->request('GET', self::GROUP_A . 'csv');

        self::assertResponseIsSuccessful();
        $response = $this->browser->getResponse();
        self::assertStringStartsWith('text/csv', (string) $response->headers->get('Content-Type'));
        self::assertStringContainsString('attachment; filename=results-cup-group-a-results.csv', (string) $response->headers->get('Content-Disposition'));
        $cacheControl = (string) $response->headers->get('Cache-Control');
        self::assertStringContainsString('private', $cacheControl);
        self::assertStringContainsString('no-store', $cacheControl);

        $rows = self::csvRows((string) $this->browser->getInternalResponse()->getContent());

        self::assertSame(['Rank', 'Table', 'Entrant', 'Members', 'Country', 'Result', 'Time (seconds)', 'Pieces placed', 'Qualified', 'Entered by', 'Entered at'], $rows[0]);
        self::assertCount(7, $rows);
        self::assertSame(['1', '1', 'Anna Fast', '', 'CZ', '1:00:00', '3600', '', 'yes', 'Sarah Williams'], array_slice($rows[1], 0, 10));
        self::assertMatchesRegularExpression('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/', $rows[1][10]);
        self::assertSame(['2', '2', 'Ben Steady'], array_slice($rows[2], 0, 3));
        self::assertSame(['2', '3', 'Cara Tied'], array_slice($rows[3], 0, 3));
        self::assertSame(['4', '4', 'Dan Unfinished', '', 'CZ', '850 / 1000 pcs', '', '850', ''], array_slice($rows[4], 0, 9));
        self::assertSame(['', '5', 'Eva Noshow', '', 'SK', 'Did not start'], array_slice($rows[5], 0, 6));
        self::assertSame(['', '', 'Filip Pending', '', 'CZ', '', '', '', '', '', ''], $rows[6]);
    }

    public function testAPairRoundListsTheMembersAndTheirCountries(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->browser->request('GET', '/en/manage-round-results/' . OfficialResultsFixture::ROUND_PAIRS . '/export/csv');

        $rows = self::csvRows((string) $this->browser->getInternalResponse()->getContent());

        self::assertSame(['1', '1', 'Puzzle Sharks', 'Anna Fast, Ben Steady', 'CZ, DE', '1:30:00'], array_slice($rows[1], 0, 6));
        // An unnamed pair is named by its members
        $unnamed = array_values(array_filter($rows, static fn (array $row): bool => $row[2] === 'Eva Noshow, Filip Pending'));
        self::assertCount(1, $unnamed);
    }

    public function testATypedFormulaNeverBecomesAFormula(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition_participant SET name = :name WHERE id = :id',
            ['name' => '=HYPERLINK("https://evil.example","Win")', 'id' => OfficialResultsFixture::PARTICIPANT_ANNA],
        );
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->browser->request('GET', self::GROUP_A . 'csv');
        $rows = self::csvRows((string) $this->browser->getInternalResponse()->getContent());
        self::assertSame('\'=HYPERLINK("https://evil.example","Win")', $rows[1][2]);

        $this->browser->request('GET', self::GROUP_A . 'xlsx');
        self::assertResponseIsSuccessful();
        self::assertSame('application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', $this->browser->getResponse()->headers->get('Content-Type'));

        $sheet = self::sheet((string) $this->browser->getInternalResponse()->getContent());
        $cell = $sheet->getCell([3, 2]);
        self::assertSame(DataType::TYPE_STRING, $cell->getDataType());
        self::assertSame('=HYPERLINK("https://evil.example","Win")', $cell->getValue());
    }

    public function testXlsxIsOneSheetWithTypedNumbers(): void
    {
        TestingLogin::asPlayer($this->browser, PlayerFixture::PLAYER_WITH_STRIPE);

        $this->browser->request('GET', self::GROUP_A . 'xlsx');

        self::assertResponseIsSuccessful();
        self::assertStringContainsString('filename=results-cup-group-a-results.xlsx', (string) $this->browser->getResponse()->headers->get('Content-Disposition'));

        $sheet = self::sheet((string) $this->browser->getInternalResponse()->getContent());
        self::assertSame('Rank', $sheet->getCell([1, 1])->getValue());
        self::assertSame('Anna Fast', $sheet->getCell([3, 2])->getValue());
        self::assertSame(DataType::TYPE_NUMERIC, $sheet->getCell([7, 2])->getDataType());
        self::assertEquals(3600, $sheet->getCell([7, 2])->getValue());
        self::assertSame('Filip Pending', $sheet->getCell([3, 7])->getValue());
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

    private static function sheet(string $content): \PhpOffice\PhpSpreadsheet\Worksheet\Worksheet
    {
        $file = tempnam(sys_get_temp_dir(), 'xlsx_test_');
        self::assertIsString($file);

        try {
            file_put_contents($file, $content);
            $spreadsheet = IOFactory::createReader('Xlsx')->load($file);
        } finally {
            unlink($file);
        }

        self::assertSame(['results'], $spreadsheet->getSheetNames());

        return $spreadsheet->getActiveSheet();
    }
}
