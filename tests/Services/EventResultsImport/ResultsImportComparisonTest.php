<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Services\EventResultsImport;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\EventResultsSheet;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Entity\PuzzleSolvingTime;
use SpeedPuzzling\Web\Entity\ResultsImportCheck;
use SpeedPuzzling\Web\Message\ApplyEventResultsTab;
use SpeedPuzzling\Web\Message\ClaimEventResultSlot;
use SpeedPuzzling\Web\Message\ReleaseEventResultSlot;
use SpeedPuzzling\Web\Query\GetEditionRounds;
use SpeedPuzzling\Web\Query\GetResultsImportStoredTabs;
use SpeedPuzzling\Web\Query\GetRoundResults;
use SpeedPuzzling\Web\Results\ResultsImport\PreviewResultRow;
use SpeedPuzzling\Web\Results\RoundResult;
use SpeedPuzzling\Web\Services\EventResultsImport\Format\SarahDoesPuzzlesFormat;
use SpeedPuzzling\Web\Services\EventResultsImport\ResultsImportComparison;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\ResultsImportScenario;
use SpeedPuzzling\Web\Value\EventResultsImport\ResultsImportStatus;
use SpeedPuzzling\Web\Value\EventResultsImport\SheetSnapshot;
use SpeedPuzzling\Web\Value\EventResultsImport\SheetSnapshotTab;
use SpeedPuzzling\Web\Value\EventResultsImport\UnreadableSheetTab;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The page predicts what the import does: the status of every event of a check against what is stored is what
 * ApplyEventResultsTab then records (the two decide in the same order), the preview's ranking is the round page's,
 * "claimed" is PuzzleSolvingTime::isClaimedByAnybody(), and a stored check parses to exactly the events (and content
 * hashes) of the sheet it was read from. Made-up events and teams.
 */
final class ResultsImportComparisonTest extends KernelTestCase
{
    use ResultsImportScenario;

    private EventResultsSheet $source;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->source = $this->createResultsSource(self::getContainer());
    }

    public function testEveryPredictedStatusIsWhatTheImportRecords(): void
    {
        $container = self::getContainer();
        $link = 'https://myspeedpuzzling.com/en/puzzle/' . PuzzleFixture::PUZZLE_500_01;
        $teams = static fn (string $name, string $type, string $date, string $puzzleLink = ''): string => self::sheetCsv([
            "First Team,0:40:00,4,,$name,Hall,\"Springfield, MN\",$date,7:00 PM,90,$type,$puzzleLink",
            'Second Team,0:45:00,4,,,,,,,,,',
        ]);
        $pairs = static fn (string $name, string $date): string => self::sheetCsv([
            "First Pair,0:40:00,2,,$name,Hall,\"Springfield, MN\",$date,7:00 PM,90,Pairs,$link",
        ]);

        // Stored before the check
        self::applyTab($container, $this->source, '201', 'Unchanged', $teams('Unchanged Night', 'Teams', '10/1/26', $link));
        self::applyTab($container, $this->source, '202', 'Changed', $teams('Changed Night', 'Teams', '10/2/26', $link));
        self::applyTab($container, $this->source, '203', 'Deleted', $teams('Deleted Night', 'Teams', '10/3/26', $link));
        self::applyTab($container, $this->source, '204', 'Other type', $teams('Other Type Night', 'Teams', '10/4/26', $link));
        $container->get(Connection::class)->executeStatement("UPDATE event_results_sheet_tab SET competition_id = NULL WHERE gid = '203'");

        $snapshot = new SheetSnapshot([
            new SheetSnapshotTab('201', 'Unchanged', $teams('Unchanged Night', 'Teams', '10/1/26', $link)),
            new SheetSnapshotTab('202', 'Changed', $teams('Changed Night Renamed', 'Teams', '10/2/26', $link)),
            new SheetSnapshotTab('203', 'Deleted', $teams('Deleted Night Again', 'Teams', '10/3/26', $link)),
            new SheetSnapshotTab('204', 'Other type', $pairs('Other Type Night', '10/4/26')),
            new SheetSnapshotTab('205', 'New', $teams('New Night', 'Teams', '10/5/26', $link)),
            new SheetSnapshotTab('206', 'No link', $teams('No Link Night', 'Teams', '10/6/26')),
            new SheetSnapshotTab('207', 'Unknown puzzle', $teams('Unknown Puzzle Night', 'Teams', '10/7/26', 'https://myspeedpuzzling.com/en/puzzle/' . Uuid::uuid7()->toString())),
            new SheetSnapshotTab('208', 'Bad date', $teams('Bad Date Night', 'Teams', '31/31/26', $link)),
            new SheetSnapshotTab('209', 'Too many pieces', self::sheetCsv([
                "Done Team,0:40:00,4,,Pieces Night,Hall,\"Springfield, MN\",10/9/26,7:00 PM,90,Teams,$link",
                'All Placed,,4,500,,,,,,,,',
            ])),
        ]);

        $check = $this->check($snapshot);
        $overview = $container->get(ResultsImportComparison::class)->compare($this->source, $check);

        $predicted = array_map(static fn ($event): array => [$event->event->key, $event->status], $overview->events);

        self::assertSame([
            ['201', ResultsImportStatus::Unchanged],
            ['202', ResultsImportStatus::Changed],
            ['203', ResultsImportStatus::EventDeleted],
            ['204', ResultsImportStatus::NeedsFixing],
            ['205', ResultsImportStatus::New],
            ['206', ResultsImportStatus::WaitingForPuzzle],
            ['207', ResultsImportStatus::WaitingForPuzzle],
            ['208', ResultsImportStatus::NeedsFixing],
            ['209', ResultsImportStatus::NeedsFixing],
        ], $predicted);

        // Now import every event of the check and see what the import makes of it
        foreach (new SarahDoesPuzzlesFormat()->parse($snapshot) as $event) {
            $container->get(MessageBusInterface::class)->dispatch(new ApplyEventResultsTab($this->source->id->toString(), $event));
            $container->get(EntityManagerInterface::class)->clear();
        }

        /** @var array<array-key, string> $recorded */
        $recorded = $container->get(Connection::class)->fetchAllKeyValue(
            'SELECT gid, status FROM event_results_sheet_tab WHERE sheet_id = :id ORDER BY gid',
            ['id' => $this->source->id->toString()],
        );

        foreach ($predicted as [$key, $status]) {
            $expected = match ($status) {
                ResultsImportStatus::New, ResultsImportStatus::Changed, ResultsImportStatus::Unchanged => 'imported',
                ResultsImportStatus::WaitingForPuzzle => 'waiting_for_puzzle',
                ResultsImportStatus::NeedsFixing => 'problem',
                ResultsImportStatus::EventDeleted => 'detached',
            };
            self::assertSame($expected, $recorded[$key] ?? null, 'event ' . $key);
        }

        // The changed name went in; the event deleted on MySpeedPuzzling was not made again
        self::assertEquals(1, $container->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM competition WHERE name = 'Changed Night Renamed'"));
        self::assertEquals(0, $container->get(Connection::class)->fetchOne("SELECT COUNT(*) FROM competition WHERE name = 'Deleted Night Again'"));

        // After the import the same check shows nothing to do any more
        $after = $container->get(ResultsImportComparison::class)->compare($this->source, $check);
        self::assertSame(ResultsImportStatus::Unchanged, $after->event('202')?->status);
        self::assertSame(ResultsImportStatus::Unchanged, $after->event('205')?->status);
    }

    public function testThePreviewRanksLikeTheRoundPage(): void
    {
        $container = self::getContainer();
        $link = 'https://myspeedpuzzling.com/en/puzzle/' . PuzzleFixture::PUZZLE_500_02;
        $csv = self::sheetCsv([
            "Slow Starters,0:52:10,4,,Ranking Night,Hall,\"Springfield, MN\",10/12/26,7:00 PM,60,Teams,$link",
            'Quick Hands,0:41:00,4,,,,,,,,,',
            'Tie One,0:45:30,3,,,,,,,,,',
            'Tie Two,0:45:30,4,,,,,,,,,',
            'Few Pieces,,4,120,,,,,,,,',
            'Many Pieces,,4,410,,,,,,,,',
            'After Whistle,1:02:00,4,,,,,,,,,',
        ]);
        $event = new SarahDoesPuzzlesFormat()->parseTab(new SheetSnapshotTab('301', 'Ranking', $csv));
        self::applyTab($container, $this->source, '301', 'Ranking', $csv);

        $competitionId = $container->get(Connection::class)->fetchOne("SELECT id FROM competition WHERE name = 'Ranking Night'");
        self::assertIsString($competitionId);
        $round = $container->get(GetEditionRounds::class)->forCompetition($competitionId)[0];
        $page = $container->get(GetRoundResults::class)->forRound($round, null)[PuzzleFixture::PUZZLE_500_02];

        self::assertSame(
            array_map(static fn (RoundResult $result): array => [$result->teamName, $result->status, $result->rank, $result->gapToLeader, $result->gapToFaster], $page),
            array_map(static fn (PreviewResultRow $row): array => [$row->teamName, $row->status, $row->rank, $row->gapToLeader, $row->gapToFaster], ResultsImportComparison::rankedRows($event)),
        );
        self::assertSame(['Quick Hands', 'Tie One', 'Tie Two', 'Slow Starters', 'Many Pieces', 'Few Pieces', 'After Whistle'], array_map(static fn (RoundResult $result): null|string => $result->teamName, $page));
    }

    public function testClaimedIsWhatTheResultSaysAlsoAfterTheFirstClaimerLeft(): void
    {
        $container = self::getContainer();
        $link = 'https://myspeedpuzzling.com/en/puzzle/' . PuzzleFixture::PUZZLE_500_03;
        self::applyTab($container, $this->source, '401', 'Claims', self::sheetCsv([
            "Shared Team,0:40:00,4,,Claims Night,Hall,\"Springfield, MN\",10/14/26,7:00 PM,90,Teams,$link",
            'Lonely Team,0:44:00,4,,,,,,,,,',
        ]));

        $connection = $container->get(Connection::class);
        $timeId = $connection->fetchOne("SELECT pst.id FROM puzzle_solving_time pst JOIN puzzling_team pt ON pt.id = pst.puzzling_team_id WHERE pt.name = 'Shared Team'");
        self::assertIsString($timeId);

        $bus = $container->get(MessageBusInterface::class);
        $bus->dispatch(new ClaimEventResultSlot($timeId, PlayerFixture::PLAYER_REGULAR));
        $container->get(EntityManagerInterface::class)->clear();
        $bus->dispatch(new ClaimEventResultSlot($timeId, PlayerFixture::PLAYER_WITH_FAVORITES));
        $container->get(EntityManagerInterface::class)->clear();
        // The first claimer leaves - the second still holds a spot
        $bus->dispatch(new ReleaseEventResultSlot($timeId, PlayerFixture::PLAYER_REGULAR));
        $container->get(EntityManagerInterface::class)->clear();

        $stored = $container->get(GetResultsImportStoredTabs::class)->ofSource($this->source->id->toString())['401'] ?? null;
        self::assertNotNull($stored);
        $entityManager = $container->get(EntityManagerInterface::class);

        foreach ($stored->results as $result) {
            $time = $connection->fetchOne(
                'SELECT eir.solving_time_id FROM event_imported_result eir JOIN event_results_sheet_tab t ON t.id = eir.tab_id WHERE t.gid = :gid AND eir.team_key = :key',
                ['gid' => '401', 'key' => $result->teamKey],
            );
            self::assertIsString($time);
            $entity = $entityManager->find(PuzzleSolvingTime::class, $time);
            self::assertNotNull($entity);
            self::assertSame($entity->isClaimedByAnybody(), $result->claimed, $result->teamKey);
        }

        self::assertTrue(($stored->results['shared team'] ?? null)?->claimed);
        self::assertFalse(($stored->results['lonely team'] ?? null)?->claimed);
    }

    public function testAStoredCheckParsesToTheSameEventsAsTheSheetItWasReadFrom(): void
    {
        $link = 'https://myspeedpuzzling.com/en/puzzle/' . PuzzleFixture::PUZZLE_500_01;
        $snapshot = new SheetSnapshot(
            [
                new SheetSnapshotTab('501', 'Ünïcödé 10/5 "quoted"', self::sheetCsv([
                    "\"Crème, Brûlée\",1:10:13,4,,\"Café Night, \"\"Special\"\"\",Café Élan,\"Springfield, MN\",10/5/26,7:00 PM,120,Teams,$link",
                    '"Ação 東京",1:12:04,3,,,,,,,,,',
                    "Tabs\tand spaces  ,1:13:00,3,,,,,,,,,",
                ])),
                new SheetSnapshotTab('502', 'Empty', ''),
            ],
            [new UnreadableSheetTab('503', 'Gone', 'Google answered 500')],
        );

        $check = $this->check($snapshot);
        $id = $check->id->toString();
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $entityManager->clear();
        $loaded = $entityManager->find(ResultsImportCheck::class, $id);
        self::assertNotNull($loaded);

        $format = new SarahDoesPuzzlesFormat();
        self::assertEquals($snapshot, $loaded->sheetSnapshot());
        self::assertSame(
            array_map(static fn ($event): string => $event->contentHash, $format->parse($snapshot)),
            array_map(static fn ($event): string => $event->contentHash, $format->parse($loaded->sheetSnapshot() ?? new SheetSnapshot([]))),
        );
    }

    private function check(SheetSnapshot $snapshot): ResultsImportCheck
    {
        $entityManager = self::getContainer()->get(EntityManagerInterface::class);
        $source = $entityManager->find(EventResultsSheet::class, $this->source->id);
        $player = $entityManager->find(Player::class, PlayerFixture::PLAYER_WITH_STRIPE);
        self::assertNotNull($source);

        $check = ResultsImportCheck::read(Uuid::uuid7(), $source, $player, new DateTimeImmutable('2026-11-16 12:00:00'), $snapshot);
        $entityManager->persist($check);
        $entityManager->flush();

        return $check;
    }
}
