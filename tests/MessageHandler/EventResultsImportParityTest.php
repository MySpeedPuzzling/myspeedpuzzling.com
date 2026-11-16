<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Nette\Utils\Json;
use PHPUnit\Framework\Attributes\DataProvider;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\EventResultsSheet;
use SpeedPuzzling\Web\Entity\EventResultsSheetTab;
use SpeedPuzzling\Web\Entity\Organization;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\SyncEventResultsSheet;
use SpeedPuzzling\Web\Services\EventResultsImport\GoogleSheetReader;
use SpeedPuzzling\Web\Services\EventResultsImport\SarahDoesPuzzlesTabParser;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Value\EventResultsImport\EventTabProblem;
use SpeedPuzzling\Web\Value\EventResultsImport\EventTabStatus;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The import generalised behind formats (docs/features/event-results-import/results-import-page.md, PR 1) must do
 * exactly what the Sarah-only import did. The expected values below - content hashes, problems, stored tabs and
 * results - were captured from the import as it was before (SarahDoesPuzzlesTabParser) and never recomputed: a
 * different content hash would apply every production tab again. Made-up events and teams.
 */
final class EventResultsImportParityTest extends KernelTestCase
{
    private const string HEADER = 'Name,Time,Team Size,Pieces placed,Contest Name,Location,City and State,Brand,Puzzle Title,Date,Start time,Time limit,Competition Type,Link to MySpeedPuzzling,Puzzle Link- manufacturer';
    private const string PUZZLE_URL = 'https://myspeedpuzzling.com/en/puzzle/' . PuzzleFixture::PUZZLE_500_02;
    private const string SPREADSHEET_ID = 'test-spreadsheet-parity-0123456789';

    /**
     * @return array<string, array{string}>
     */
    public static function sheetTabs(): array
    {
        return [
            'teams' => [self::lines(
                self::HEADER,
                'Puzzle Pals,1:10:13,4,,Maple Street Puzzle Night,Maple Street Taproom,"Springfield, MN",Some Brand,Some Puzzle,10/5/26,7:00 PM,120,Teams,' . self::PUZZLE_URL . ',https://example.com/puzzle',
                '"Edge Lords, Inc",1:12:04,3,,,,,,,,,,,,',
            )],
            'pairs with an unfinished pair' => [self::lines(
                self::HEADER,
                'Two of Us,0:24:48,2,,Corner Cafe Pairs,Corner Cafe,"Springfield, MN",,,10/7/26,7:00 PM,90,Pairs,' . self::PUZZLE_URL . ',',
                'Late Pair,,2,300,,,,,,,,,,,',
            )],
            'unfinished without a time limit' => [self::lines(
                self::HEADER,
                'Two of Us,0:24:48,2,,Corner Cafe Pairs,Corner Cafe,"Springfield, MN",,,10/7/26,7:00 PM,,Pairs,' . self::PUZZLE_URL . ',',
                'Late Pair,,2,300,,,,,,,,,,,',
            )],
            'only the day' => [self::lines(
                self::HEADER,
                'Puzzle Pals,1:10:13,4,,Library Night,Town Library,"Springfield, MN",,,2026-10-09,,,Teams,' . self::PUZZLE_URL . ',',
            )],
            'waiting for its puzzle' => [self::lines(
                self::HEADER,
                'Puzzle Pals,1:10:13,4,,Brewery Night,Old Brewery,"Springfield, MN",,,10/12/26,6:30 PM,120,Teams,,',
            )],
            'unreadable puzzle link' => [self::lines(
                self::HEADER,
                'Puzzle Pals,1:10:13,4,,Brewery Night,Old Brewery,"Springfield, MN",,,10/12/26,6:30 PM,120,Teams,https://example.com/puzzle/123,',
            )],
            'row problems' => [self::lines(
                self::HEADER,
                'Puzzle Pals,1:10:13,4,,Maple Street Puzzle Night,Maple Street Taproom,"Springfield, MN",,,10/5/26,7:00 PM,120,Teams,' . self::PUZZLE_URL . ',',
                'puzzle pals,1:11:00,4,,,,,,,,,,,,',
                'Slowpokes,one hour,4,,,,,,,,,,,,',
                'Duo,1:20:00,2,,,,,,,,,,,,',
                ',1:30:00,4,,,,,,,,,,,,',
                'Nothing Yet,,4,,,,,,,,,,,,',
                'Fast Four,0:59:00,4,,,,,,,,,,,,',
            )],
            'missing column' => [self::lines(
                'Name,Time,Contest Name,Location,City and State,Date,Competition Type,Link to MySpeedPuzzling',
                'Puzzle Pals,1:10:13,Maple Street Puzzle Night,Taproom,"Springfield, MN",10/5/26,Teams,' . self::PUZZLE_URL,
            )],
            'no teams' => [self::lines(self::HEADER)],
            'empty file' => [''],
        ];
    }

    /**
     * Captured from SarahDoesPuzzlesTabParser before the import went behind formats.
     *
     * @return array<string, array{hash: string, problems: list<array{code: string, detail: null|string, line: null|int}>, rows: list<array{int, string, int, null|int, null|int}>}>
     */
    private static function expectedParses(): array
    {
        return [
            'teams' => [
                'hash' => '82c571f5cf3a743cc3abd42abd27e71eabaa6bf5',
                'problems' => [],
                'rows' => [
                    [2, 'Puzzle Pals', 4, 4213, null],
                    [3, 'Edge Lords, Inc', 3, 4324, null],
                ],
            ],
            'pairs with an unfinished pair' => [
                'hash' => '6d6905d231a02deea3047d09a4d7d2e80b15314a',
                'problems' => [],
                'rows' => [
                    [2, 'Two of Us', 2, 1488, null],
                    [3, 'Late Pair', 2, null, 300],
                ],
            ],
            'unfinished without a time limit' => [
                'hash' => 'ca74af1a38c8b8da6944d8eb8b5246bcac814840',
                'problems' => [
                    [
                        'code' => 'unfinished_without_time_limit',
                        'detail' => 'Late Pair',
                        'line' => 3,
                    ],
                ],
                'rows' => [
                    [2, 'Two of Us', 2, 1488, null],
                    [3, 'Late Pair', 2, null, 300],
                ],
            ],
            'only the day' => [
                'hash' => 'b307fafc509e5b18dcc8a6773801cb113ca3230f',
                'problems' => [],
                'rows' => [
                    [2, 'Puzzle Pals', 4, 4213, null],
                ],
            ],
            'waiting for its puzzle' => [
                'hash' => '861ed2b57c5c50869684ca55f77825e8b884f600',
                'problems' => [],
                'rows' => [
                    [2, 'Puzzle Pals', 4, 4213, null],
                ],
            ],
            'unreadable puzzle link' => [
                'hash' => '2e6fa72f679ac473d63ce56649533d3f5f7a03b6',
                'problems' => [
                    [
                        'code' => 'unreadable_puzzle_link',
                        'detail' => 'https://example.com/puzzle/123',
                        'line' => null,
                    ],
                ],
                'rows' => [
                    [2, 'Puzzle Pals', 4, 4213, null],
                ],
            ],
            'row problems' => [
                'hash' => '78735fcf27285274bd1a61fd454fc59a8f73087b',
                'problems' => [
                    [
                        'code' => 'unreadable_time',
                        'detail' => 'Slowpokes: one hour',
                        'line' => 4,
                    ],
                    [
                        'code' => 'team_size_does_not_fit_category',
                        'detail' => 'Duo',
                        'line' => 5,
                    ],
                    [
                        'code' => 'row_without_team_name',
                        'detail' => null,
                        'line' => 6,
                    ],
                    [
                        'code' => 'row_without_result',
                        'detail' => 'Nothing Yet',
                        'line' => 7,
                    ],
                    [
                        'code' => 'team_named_twice',
                        'detail' => 'Puzzle Pals',
                        'line' => 3,
                    ],
                ],
                'rows' => [
                    [8, 'Fast Four', 4, 3540, null],
                ],
            ],
            'missing column' => [
                'hash' => 'ae47b9694a2b91326ab6be9c8350fc948fcd0bec',
                'problems' => [
                    [
                        'code' => 'missing_column',
                        'detail' => 'Team Size',
                        'line' => null,
                    ],
                ],
                'rows' => [],
            ],
            'no teams' => [
                'hash' => '37dfac37b35b0e68e30cf9f5a5229a76a53735e3',
                'problems' => [
                    [
                        'code' => 'no_teams',
                        'detail' => null,
                        'line' => null,
                    ],
                ],
                'rows' => [],
            ],
            'empty file' => [
                'hash' => '6f7f0909a112cbd262dff477d40bc51f3e5361f3',
                'problems' => [
                    [
                        'code' => 'missing_column',
                        'detail' => 'Name',
                        'line' => null,
                    ],
                    [
                        'code' => 'missing_column',
                        'detail' => 'Time',
                        'line' => null,
                    ],
                    [
                        'code' => 'missing_column',
                        'detail' => 'Team Size',
                        'line' => null,
                    ],
                    [
                        'code' => 'missing_column',
                        'detail' => 'Contest Name',
                        'line' => null,
                    ],
                    [
                        'code' => 'missing_column',
                        'detail' => 'Location',
                        'line' => null,
                    ],
                    [
                        'code' => 'missing_column',
                        'detail' => 'City and State',
                        'line' => null,
                    ],
                    [
                        'code' => 'missing_column',
                        'detail' => 'Date',
                        'line' => null,
                    ],
                    [
                        'code' => 'missing_column',
                        'detail' => 'Competition Type',
                        'line' => null,
                    ],
                    [
                        'code' => 'missing_column',
                        'detail' => 'Link to MySpeedPuzzling',
                        'line' => null,
                    ],
                ],
                'rows' => [],
            ],
        ];
    }

    #[DataProvider('sheetTabs')]
    public function testEveryTabParsesExactlyAsBefore(string $csv): void
    {
        $name = $this->dataName();
        self::assertIsString($name);
        $expected = self::expectedParses()[$name] ?? null;

        $parsed = self::parse('101', 'Some tab', $csv);
        $actual = [
            'hash' => $parsed['hash'],
            'problems' => $parsed['problems'],
            'rows' => $parsed['rows'],
        ];

        self::assertSame($expected, $actual, var_export($actual, true));
    }

    public function testASyncedSheetStoresExactlyWhatItStoredBefore(): void
    {
        $sheetId = $this->registerSheet();
        $this->googleAnswers();

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new SyncEventResultsSheet($sheetId));
        $state = $this->storedState($sheetId);

        self::assertSame(self::expectedStoredState(), $state, var_export($state, true));

        // Run again: an unchanged imported tab is skipped by its content hash - nothing at all moves
        $appliedAt = $this->database()->fetchAllKeyValue('SELECT gid, applied_at FROM event_results_sheet_tab WHERE sheet_id = :id AND status = :status', ['id' => $sheetId, 'status' => 'imported']);
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new SyncEventResultsSheet($sheetId));

        self::assertSame(self::expectedStoredState(), $this->storedState($sheetId));
        self::assertSame($appliedAt, $this->database()->fetchAllKeyValue('SELECT gid, applied_at FROM event_results_sheet_tab WHERE sheet_id = :id AND status = :status', ['id' => $sheetId, 'status' => 'imported']));
    }

    /**
     * Production: a tab imported by the old code - its stored content hash is the old one. The same sheet read again
     * must be recognised as unchanged, never applied again (here: its results would be created anew).
     */
    public function testATabImportedBeforeIsNotAppliedAgain(): void
    {
        $sheetId = $this->registerSheet();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $sheet = $entityManager->find(EventResultsSheet::class, $sheetId);
        $competition = $entityManager->find(Competition::class, CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024);
        self::assertNotNull($sheet);
        self::assertNotNull($competition);

        $tab = new EventResultsSheetTab(Uuid::uuid7(), $sheet, '11', 'Maple 10/5');
        $tab->record('Maple 10/5', EventTabStatus::Imported, [], self::expectedParses()['teams']['hash'] ?? '', $competition, new DateTimeImmutable('2026-11-13 12:00:00'));
        $entityManager->persist($tab);
        $entityManager->flush();

        $this->googleAnswers();
        self::getContainer()->get(MessageBusInterface::class)->dispatch(new SyncEventResultsSheet($sheetId));

        $stored = $this->database()->fetchAssociative('SELECT status, applied_at, competition_id, (SELECT COUNT(*) FROM event_imported_result r WHERE r.tab_id = t.id) AS results FROM event_results_sheet_tab t WHERE t.sheet_id = :id AND t.gid = :gid', ['id' => $sheetId, 'gid' => '11']);
        self::assertIsArray($stored);
        self::assertSame('imported', $stored['status']);
        self::assertSame(CompetitionFixture::COMPETITION_CZECH_NATIONALS_2024, $stored['competition_id']);
        self::assertSame('2026-11-13 12:00:00', substr((string) $stored['applied_at'], 0, 19), 'Not applied again');
        self::assertSame(0, (int) $stored['results']);
    }

    /**
     * @return array{hash: string, problems: list<array{code: string, detail: null|string, line: null|int}>, rows: list<array{int, string, int, null|int, null|int}>}
     */
    private static function parse(string $gid, string $title, string $csv): array
    {
        $parsed = new SarahDoesPuzzlesTabParser()->parse($csv);

        return [
            'hash' => $parsed->contentHash,
            'problems' => array_map(static fn (EventTabProblem $problem): array => $problem->toArray(), $parsed->problems),
            'rows' => array_map(static fn ($row): array => [$row->line, $row->teamName, $row->teamSize, $row->seconds, $row->piecesPlaced], $parsed->rows),
        ];
    }

    /**
     * Captured from the import before it went behind formats.
     *
     * @return array<string, mixed>
     */
    private static function expectedStoredState(): array
    {
        return [
            'sheet' => [
                'last_fetch_error' => 'Tab "Broken": Google answered https://docs.google.com/spreadsheets/d/test-spreadsheet-parity-0123456789/export?format=csv&gid=55 with 500  - is the sheet shared "Anyone with the link"?',
                'fetched' => true,
            ],
            'tabs' => [
                [
                    'gid' => '11',
                    'title' => 'Maple 10/5',
                    'status' => 'imported',
                    'problems' => [],
                    'content_hash' => '82c571f5cf3a743cc3abd42abd27e71eabaa6bf5',
                    'has_event' => true,
                    'first_imported' => true,
                ],
                [
                    'gid' => '22',
                    'title' => 'Corner 10/7',
                    'status' => 'imported',
                    'problems' => [],
                    'content_hash' => '6d6905d231a02deea3047d09a4d7d2e80b15314a',
                    'has_event' => true,
                    'first_imported' => true,
                ],
                [
                    'gid' => '33',
                    'title' => 'Brewery 10/12',
                    'status' => 'waiting_for_puzzle',
                    'problems' => [],
                    'content_hash' => '861ed2b57c5c50869684ca55f77825e8b884f600',
                    'has_event' => false,
                    'first_imported' => false,
                ],
                [
                    'gid' => '44',
                    'title' => 'Corner no limit',
                    'status' => 'problem',
                    'problems' => [
                        [
                            'code' => 'unfinished_without_time_limit',
                            'detail' => 'Late Pair',
                            'line' => 3,
                        ],
                    ],
                    'content_hash' => 'ca74af1a38c8b8da6944d8eb8b5246bcac814840',
                    'has_event' => false,
                    'first_imported' => false,
                ],
            ],
            'events' => [
                [
                    'gid' => '11',
                    'name' => 'Maple Street Puzzle Night',
                    'location' => 'Maple Street Taproom, Springfield, MN',
                    'location_country_code' => 'us',
                    'date_from' => '2026-10-05 00:00:00',
                    'date_to' => '2026-10-05 00:00:00',
                    'is_online' => false,
                    'approved' => true,
                    'round_name' => 'Teams',
                    'category' => 'team',
                    'minutes_limit' => 120,
                    'starts_at' => '2026-10-06 00:00:00',
                    'timezone' => 'America/Chicago',
                    'team_size' => 4,
                    'start_time_known' => true,
                    'puzzles' => '018d0003-0000-0000-0000-000000000002',
                ],
                [
                    'gid' => '22',
                    'name' => 'Corner Cafe Pairs',
                    'location' => 'Corner Cafe, Springfield, MN',
                    'location_country_code' => 'us',
                    'date_from' => '2026-10-07 00:00:00',
                    'date_to' => '2026-10-07 00:00:00',
                    'is_online' => false,
                    'approved' => true,
                    'round_name' => 'Pairs',
                    'category' => 'duo',
                    'minutes_limit' => 90,
                    'starts_at' => '2026-10-08 00:00:00',
                    'timezone' => 'America/Chicago',
                    'team_size' => null,
                    'start_time_known' => true,
                    'puzzles' => '018d0003-0000-0000-0000-000000000002',
                ],
            ],
            'results' => [
                [
                    'gid' => '11',
                    'team_key' => 'edge lords, inc',
                    'team_name' => 'Edge Lords, Inc',
                    'seconds_to_solve' => 4324,
                    'pieces_placed' => null,
                    'seconds_spent' => null,
                    'finished_later_seconds' => null,
                    'puzzlers_count' => 3,
                    'player_id' => null,
                    'created_via' => 'event_import',
                    'first_attempt' => false,
                    'finished_at' => '2026-10-05 00:00:00',
                    'puzzle_id' => '018d0003-0000-0000-0000-000000000002',
                    'has_round' => true,
                ],
                [
                    'gid' => '11',
                    'team_key' => 'puzzle pals',
                    'team_name' => 'Puzzle Pals',
                    'seconds_to_solve' => 4213,
                    'pieces_placed' => null,
                    'seconds_spent' => null,
                    'finished_later_seconds' => null,
                    'puzzlers_count' => 4,
                    'player_id' => null,
                    'created_via' => 'event_import',
                    'first_attempt' => false,
                    'finished_at' => '2026-10-05 00:00:00',
                    'puzzle_id' => '018d0003-0000-0000-0000-000000000002',
                    'has_round' => true,
                ],
                [
                    'gid' => '22',
                    'team_key' => 'late pair',
                    'team_name' => 'Late Pair',
                    'seconds_to_solve' => null,
                    'pieces_placed' => 300,
                    'seconds_spent' => 5400,
                    'finished_later_seconds' => null,
                    'puzzlers_count' => 2,
                    'player_id' => null,
                    'created_via' => 'event_import',
                    'first_attempt' => false,
                    'finished_at' => '2026-10-07 00:00:00',
                    'puzzle_id' => '018d0003-0000-0000-0000-000000000002',
                    'has_round' => true,
                ],
                [
                    'gid' => '22',
                    'team_key' => 'two of us',
                    'team_name' => 'Two of Us',
                    'seconds_to_solve' => 1488,
                    'pieces_placed' => null,
                    'seconds_spent' => null,
                    'finished_later_seconds' => null,
                    'puzzlers_count' => 2,
                    'player_id' => null,
                    'created_via' => 'event_import',
                    'first_attempt' => false,
                    'finished_at' => '2026-10-07 00:00:00',
                    'puzzle_id' => '018d0003-0000-0000-0000-000000000002',
                    'has_round' => true,
                ],
            ],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function storedState(string $sheetId): array
    {
        $database = $this->database();

        $tabs = $database->fetchAllAssociative(
            'SELECT gid, title, status, problems, content_hash, competition_id IS NOT NULL AS has_event, first_imported_at IS NOT NULL AS first_imported
             FROM event_results_sheet_tab WHERE sheet_id = :id ORDER BY gid',
            ['id' => $sheetId],
        );

        foreach ($tabs as &$tab) {
            $problems = Json::decode(self::text($tab['problems']), forceArrays: true);
            assert(is_array($problems));
            $tab['problems'] = array_map(static function (mixed $problem): array {
                assert(is_array($problem));
                ksort($problem);

                return $problem;
            }, $problems);
        }

        unset($tab);

        $events = $database->fetchAllAssociative(
            'SELECT tab.gid, c.name, c.location, c.location_country_code, c.date_from, c.date_to, c.is_online, c.approved_at IS NOT NULL AS approved,
                r.name AS round_name, r.category, r.minutes_limit, r.starts_at, r.timezone, r.team_size, r.start_time_known,
                (SELECT string_agg(rp.puzzle_id::text, \',\') FROM competition_round_puzzle rp WHERE rp.round_id = r.id) AS puzzles
             FROM event_results_sheet_tab tab
             JOIN competition c ON c.id = tab.competition_id
             JOIN competition_round r ON r.competition_id = c.id
             WHERE tab.sheet_id = :id ORDER BY tab.gid',
            ['id' => $sheetId],
        );

        $results = $database->fetchAllAssociative(
            'SELECT tab.gid, imported.team_key, team.name AS team_name, pst.seconds_to_solve, pst.pieces_placed, pst.seconds_spent,
                pst.finished_later_seconds, pst.puzzlers_count, pst.player_id, pst.created_via, pst.first_attempt, pst.finished_at,
                pst.puzzle_id, pst.competition_round_id IS NOT NULL AS has_round
             FROM event_imported_result imported
             JOIN event_results_sheet_tab tab ON tab.id = imported.tab_id
             JOIN puzzle_solving_time pst ON pst.id = imported.solving_time_id
             LEFT JOIN puzzling_team team ON team.id = pst.puzzling_team_id
             WHERE tab.sheet_id = :id ORDER BY tab.gid, imported.team_key',
            ['id' => $sheetId],
        );

        $sheet = $database->fetchAssociative('SELECT last_fetch_error, last_fetched_at IS NOT NULL AS fetched FROM event_results_sheet WHERE id = :id', ['id' => $sheetId]);

        return ['sheet' => $sheet, 'tabs' => $tabs, 'events' => $events, 'results' => $results];
    }

    private function registerSheet(): string
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $organization = $entityManager->find(Organization::class, OrganizationFixture::ORGANIZATION_RIVERBEND);
        $creator = $entityManager->find(Player::class, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertNotNull($organization);
        self::assertNotNull($creator);

        $sheet = new EventResultsSheet(Uuid::uuid7(), self::SPREADSHEET_ID, $organization, $creator, 'America/Chicago', 'us', new DateTimeImmutable('2026-11-01'));
        $entityManager->persist($sheet);
        $entityManager->flush();

        return $sheet->id->toString();
    }

    /**
     * Five tabs: imported teams, imported pairs with an unfinished pair, waiting for its puzzle, a problem, and one
     * Google does not give us.
     */
    private function googleAnswers(): void
    {
        $tabs = self::sheetTabs();
        $answers = [
            'htmlview' => new MockResponse(
                '<script>items.push({name: "Maple 10\/5", pageUrl: "x", gid: "11",initialSheet: false});'
                . 'items.push({name: "Corner 10\/7", pageUrl: "x", gid: "22",initialSheet: false});'
                . 'items.push({name: "Brewery 10\/12", pageUrl: "x", gid: "33",initialSheet: false});'
                . 'items.push({name: "Corner no limit", pageUrl: "x", gid: "44",initialSheet: false});'
                . 'items.push({name: "Broken", pageUrl: "x", gid: "55",initialSheet: false});</script>',
                ['response_headers' => ['content-type' => 'text/html']],
            ),
            'gid=11' => self::csvResponse($tabs['teams'][0]),
            'gid=22' => self::csvResponse($tabs['pairs with an unfinished pair'][0]),
            'gid=33' => self::csvResponse($tabs['waiting for its puzzle'][0]),
            'gid=44' => self::csvResponse($tabs['unfinished without a time limit'][0]),
            'gid=55' => new MockResponse('', ['http_code' => 500]),
        ];

        self::getContainer()->set(GoogleSheetReader::class, new GoogleSheetReader(new MockHttpClient(
            static function (string $method, string $url) use ($answers): MockResponse {
                foreach ($answers as $piece => $response) {
                    if (str_contains($url, $piece)) {
                        // A MockResponse is read once - a second sync gets a fresh copy of it
                        return clone $response;
                    }
                }

                return new MockResponse('', ['http_code' => 404]);
            },
        )));
    }

    private static function csvResponse(string $csv): MockResponse
    {
        return new MockResponse($csv, ['response_headers' => ['content-type' => 'text/csv']]);
    }

    private static function lines(string ...$lines): string
    {
        return implode("\n", $lines) . "\n";
    }

    private function database(): Connection
    {
        return self::getContainer()->get(Connection::class);
    }

    private static function text(mixed $value): string
    {
        assert(is_string($value));

        return $value;
    }
}
