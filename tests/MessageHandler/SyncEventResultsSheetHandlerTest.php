<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\EventResultsSheet;
use SpeedPuzzling\Web\Entity\Organization;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Message\SyncEventResultsSheet;
use SpeedPuzzling\Web\Services\EventResultsImport\GoogleSheetReader;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The scheduled read of an organizer's sheet: every tab applied on its own, a sheet Google does not give us recorded
 * as such - nothing imported, nothing removed. Made-up events and teams.
 */
final class SyncEventResultsSheetHandlerTest extends KernelTestCase
{
    private const string SPREADSHEET_ID = 'test-spreadsheet-sync-0123456789';
    private const string HEADER = 'Name,Time,Team Size,Contest Name,Location,City and State,Date,Start time,Time limit,Competition Type,Link to MySpeedPuzzling';

    private Connection $database;
    private string $sheetId;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->database = self::getContainer()->get(Connection::class);
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);

        $organization = $entityManager->find(Organization::class, OrganizationFixture::ORGANIZATION_RIVERBEND);
        $creator = $entityManager->find(Player::class, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertNotNull($organization);
        self::assertNotNull($creator);

        $sheet = new EventResultsSheet(Uuid::uuid7(), self::SPREADSHEET_ID, $organization, $creator, 'America/Chicago', 'us', new DateTimeImmutable('2026-11-01'));
        $entityManager->persist($sheet);
        $entityManager->flush();
        $this->sheetId = $sheet->id->toString();
    }

    public function testEveryTabIsAppliedOnItsOwn(): void
    {
        $this->googleAnswers([
            'htmlview' => new MockResponse(
                '<script>items.push({name: "Maple 10\/5", pageUrl: "x", gid: "11",initialSheet: false});'
                . 'items.push({name: "Corner 10\/7", pageUrl: "x", gid: "22",initialSheet: false});</script>',
                ['response_headers' => ['content-type' => 'text/html']],
            ),
            'gid=11' => $this->csv('Maple Street Puzzle Night', 'Teams', 'Puzzle Pals,1:10:13,4', true),
            'gid=22' => $this->csv('Corner Cafe Pairs', 'Pairs', 'Two of Us,0:24:48,2', false),
        ]);

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new SyncEventResultsSheet($this->sheetId));

        self::assertSame(
            ['11' => 'imported', '22' => 'waiting_for_puzzle'],
            $this->database->fetchAllKeyValue('SELECT gid, status FROM event_results_sheet_tab WHERE sheet_id = :id ORDER BY gid', ['id' => $this->sheetId]),
        );
        self::assertSame(1, $this->rowCount('SELECT COUNT(*) FROM event_imported_result'));

        $sheet = $this->database->fetchAssociative('SELECT last_fetched_at, last_fetch_error FROM event_results_sheet WHERE id = :id', ['id' => $this->sheetId]);
        self::assertIsArray($sheet);
        self::assertNotNull($sheet['last_fetched_at']);
        self::assertNull($sheet['last_fetch_error']);
    }

    public function testASheetGoogleDoesNotGiveUsIsRecordedAndNothingChanges(): void
    {
        $this->googleAnswers([
            'htmlview' => new MockResponse('<html>Sign in</html>', ['http_code' => 401, 'response_headers' => ['content-type' => 'text/html']]),
        ]);

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new SyncEventResultsSheet($this->sheetId));

        $error = $this->database->fetchOne('SELECT last_fetch_error FROM event_results_sheet WHERE id = :id', ['id' => $this->sheetId]);
        self::assertIsString($error);
        self::assertStringContainsString('401', $error);
        self::assertSame(0, $this->rowCount('SELECT COUNT(*) FROM event_results_sheet_tab'));
    }

    public function testATabGoogleDoesNotGiveUsLeavesTheOthersAlone(): void
    {
        $this->googleAnswers([
            'htmlview' => new MockResponse(
                '<script>items.push({name: "Maple", pageUrl: "x", gid: "11",initialSheet: false});'
                . 'items.push({name: "Broken", pageUrl: "x", gid: "33",initialSheet: false});</script>',
                ['response_headers' => ['content-type' => 'text/html']],
            ),
            'gid=11' => $this->csv('Maple Street Puzzle Night', 'Teams', 'Puzzle Pals,1:10:13,4', true),
            'gid=33' => new MockResponse('', ['http_code' => 500]),
        ]);

        self::getContainer()->get(MessageBusInterface::class)->dispatch(new SyncEventResultsSheet($this->sheetId));

        self::assertSame(['11' => 'imported'], $this->database->fetchAllKeyValue('SELECT gid, status FROM event_results_sheet_tab'));
        self::assertIsString($this->database->fetchOne('SELECT last_fetch_error FROM event_results_sheet WHERE id = :id', ['id' => $this->sheetId]));
    }

    /**
     * @param array<string, MockResponse> $answers by a piece of the requested URL
     */
    private function googleAnswers(array $answers): void
    {
        self::getContainer()->set(GoogleSheetReader::class, new GoogleSheetReader(new MockHttpClient(
            static function (string $method, string $url) use ($answers): MockResponse {
                foreach ($answers as $piece => $response) {
                    if (str_contains($url, $piece)) {
                        return $response;
                    }
                }

                return new MockResponse('', ['http_code' => 404]);
            },
        )));
    }

    private function csv(string $event, string $type, string $team, bool $withPuzzle): MockResponse
    {
        $link = $withPuzzle ? 'https://myspeedpuzzling.com/en/puzzle/' . PuzzleFixture::PUZZLE_500_02 : '';
        [$name, $time, $size] = explode(',', $team);

        return new MockResponse(
            self::HEADER . "\n" . sprintf('%s,%s,%s,%s,Taproom,"Springfield, MN",10/5/26,7:00 PM,120,%s,%s', $name, $time, $size, $event, $type, $link) . "\n",
            ['response_headers' => ['content-type' => 'text/csv']],
        );
    }

    private function rowCount(string $sql): int
    {
        $count = $this->database->fetchOne($sql);
        self::assertTrue(is_numeric($count));

        return (int) $count;
    }
}
