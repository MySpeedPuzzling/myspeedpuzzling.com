<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\ConsoleCommands;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\EventResultsSheet;
use SpeedPuzzling\Web\Entity\Organization;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Services\EventResultsImport\GoogleSheetReader;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use Symfony\Bundle\FrameworkBundle\Console\Application;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;

/**
 * `myspeedpuzzling:sync-sarah-does-puzzles-results --dry-run` says what each tab would become - unfinished teams
 * counted, a tab with unfinished teams and no Time limit a problem - and writes nothing
 * (docs/features/event-results-import/README.md "Unfinished results (D9)"). Made-up events and teams.
 */
final class SyncSarahDoesPuzzlesResultsDryRunTest extends KernelTestCase
{
    private const string HEADER = 'Name,Time,Team Size,Pieces placed,Contest Name,Location,City and State,Date,Start time,Time limit,Competition Type,Link to MySpeedPuzzling';

    public function testTheDryRunCountsUnfinishedTeamsAndNamesTheMissingLimit(): void
    {
        self::bootKernel();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $organization = $entityManager->find(Organization::class, OrganizationFixture::ORGANIZATION_RIVERBEND);
        $creator = $entityManager->find(Player::class, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertNotNull($organization);
        self::assertNotNull($creator);
        $sheet = new EventResultsSheet(Uuid::uuid7(), 'test-spreadsheet-dry-run-0123456789', $organization, $creator, 'America/Chicago', 'us', new DateTimeImmutable('2026-11-01'));
        $entityManager->persist($sheet);
        $entityManager->flush();

        $link = 'https://myspeedpuzzling.com/en/puzzle/' . PuzzleFixture::PUZZLE_500_02;
        $answers = [
            'htmlview' => new MockResponse(
                '<script>items.push({name: "Maple 10\/5", pageUrl: "x", gid: "11",initialSheet: false});'
                . 'items.push({name: "Corner 10\/7", pageUrl: "x", gid: "22",initialSheet: false});</script>',
                ['response_headers' => ['content-type' => 'text/html']],
            ),
            'gid=11' => self::csv("Puzzle Pals,1:10:13,4,,Maple Street,Taproom,\"Springfield, MN\",10/5/26,7:00 PM,120,Teams,$link\nOut of Time,,4,412,,,,,,,,\nEdge Lords,,3,388,,,,,,,,"),
            'gid=22' => self::csv("Two of Us,0:24:48,2,,Corner Cafe,Cafe,\"Springfield, MN\",10/7/26,7:00 PM,,Pairs,$link\nLate Pair,,2,300,,,,,,,,"),
        ];
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

        $kernel = self::$kernel;
        self::assertNotNull($kernel);
        $tester = new CommandTester(new Application($kernel)->find('myspeedpuzzling:sync-sarah-does-puzzles-results'));
        $tester->execute(['--sheet' => $sheet->id->toString(), '--dry-run' => true]);

        $tester->assertCommandIsSuccessful();
        $output = preg_replace('/\s+/', ' ', $tester->getDisplay()) ?? '';
        self::assertStringContainsString('Maple 10/5 importable (puzzle checked on import) 3 (2 unfinished)', $output);
        self::assertStringContainsString('Corner 10/7 problem 2 (1 unfinished) unfinished_without_time_limit (Late Pair) @3', $output);

        self::assertSame(0, (int) self::getContainer()->get(Connection::class)->fetchOne(
            'SELECT COUNT(*) FROM event_results_sheet_tab WHERE sheet_id = :id',
            ['id' => $sheet->id->toString()],
        ));
    }

    private static function csv(string $rows): MockResponse
    {
        return new MockResponse(self::HEADER . "\n" . $rows . "\n", ['response_headers' => ['content-type' => 'text/csv']]);
    }
}
