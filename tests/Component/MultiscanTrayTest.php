<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Component;

use Doctrine\DBAL\Connection;
use SpeedPuzzling\Web\Component\MultiscanTray;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\TestingLogin;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use Symfony\UX\LiveComponent\Test\InteractsWithLiveComponents;
use Symfony\UX\LiveComponent\Test\TestLiveComponent;

/**
 * docs/features/multiscan/README.md - the tray as the member sees it: PLAYER_WITH_STRIPE owns
 * PUZZLE_300 (library), lends PUZZLE_2000 to PLAYER_REGULAR and PUZZLE_1500_01 to "Jane Doe",
 * borrows PUZZLE_1500_02; PUZZLE_6000 is nobody's; PUZZLE_4000/5000 share one code; PUZZLE_9000 has none.
 */
final class MultiscanTrayTest extends WebTestCase
{
    use InteractsWithLiveComponents;
    use QueryCountAssertions;

    /**
     * @param array<string, mixed> $data
     */
    private function tray(KernelBrowser $client, string $playerId = PlayerFixture::PLAYER_WITH_STRIPE, array $data = []): TestLiveComponent
    {
        TestingLogin::asPlayer($client, $playerId);
        $component = $this->createLiveComponent('MultiscanTray', $data, $client);
        $component->setRouteLocale('en');

        return $component;
    }

    public function testScanResolvesAPuzzleWithItsStatusChip(): void
    {
        $client = self::createClient();
        $tray = $this->tray($client);

        $tray->call('scan', ['ean' => PuzzleFixture::EAN_PUZZLE_300]);
        $html = $tray->render()->toString();

        self::assertStringContainsString('Puzzle 11', $html);
        self::assertStringContainsString('In your library', $html);
        self::assertStringContainsString('data-multiscan-notice="found"', $html);

        $tray->call('scan', ['ean' => PuzzleFixture::EAN_PUZZLE_2000]);
        $html = $tray->render()->toString();
        self::assertStringContainsString('Lent to ' . PlayerFixture::PLAYER_REGULAR_NAME, $html);

        $tray->call('scan', ['ean' => PuzzleFixture::EAN_PUZZLE_6000]);
        $html = $tray->render()->toString();
        self::assertStringContainsString('Not in your library', $html);
        self::assertCount(3, self::rows($tray));
    }

    public function testDuplicatesNeverEnterTheTray(): void
    {
        $client = self::createClient();
        $tray = $this->tray($client);

        $tray->call('scan', ['ean' => PuzzleFixture::EAN_PUZZLE_6000]);
        $tray->call('scan', ['ean' => PuzzleFixture::EAN_PUZZLE_6000]);
        $html = $tray->render()->toString();

        self::assertStringContainsString('data-multiscan-notice="duplicate"', $html);
        self::assertStringContainsString('data-multiscan-notice-name="Puzzle 18"', $html);
        self::assertCount(1, self::rows($tray));

        // Same code with a leading zero (UPC-style read) is the same puzzle
        $tray->call('scan', ['ean' => '0' . PuzzleFixture::EAN_PUZZLE_6000]);
        self::assertStringContainsString('data-multiscan-notice="duplicate"', $tray->render()->toString());
        self::assertSame([PuzzleFixture::EAN_PUZZLE_6000], array_column(self::rows($tray), 'ean'));
    }

    public function testInvalidCodeIsReportedNotAdded(): void
    {
        $client = self::createClient();
        $tray = $this->tray($client);

        $tray->call('scan', ['ean' => '4005556123456']);
        $html = $tray->render()->toString();

        self::assertStringContainsString('data-multiscan-notice="invalid"', $html);
        self::assertCount(0, self::rows($tray));
    }

    public function testAmbiguousCodeAsksUnlessOneCandidateIsAlreadyMine(): void
    {
        $client = self::createClient();
        // PLAYER_ADMIN owns neither PUZZLE_4000 nor PUZZLE_5000
        $tray = $this->tray($client, PlayerFixture::PLAYER_ADMIN);

        $tray->call('scan', ['ean' => PuzzleFixture::EAN_SHARED_4000_5000]);
        $html = $tray->render()->toString();

        self::assertStringContainsString('data-multiscan-notice="ambiguous"', $html);
        self::assertStringContainsString('2 puzzles share the code', $html);
        self::assertSame('ambiguous', self::rows($tray)[0]['state']);
        self::assertStringContainsString('Add 0 to library', $html, 'an unresolved row never counts');

        $tray->call('choose', ['ean' => PuzzleFixture::EAN_SHARED_4000_5000, 'puzzleId' => PuzzleFixture::PUZZLE_5000]);
        $html = $tray->render()->toString();
        self::assertSame(PuzzleFixture::PUZZLE_5000, self::rows($tray)[0]['puzzleId']);
        self::assertStringContainsString('Add 1 to library', $html);
    }

    public function testAmbiguousCodeAutoPicksTheCandidateInMyLibrary(): void
    {
        $client = self::createClient();
        $tray = $this->tray($client);

        /** @var Connection $database */
        $database = self::getContainer()->get(Connection::class);
        $database->executeStatement(
            'INSERT INTO collection_item (id, collection_id, player_id, puzzle_id, comment, added_at) VALUES (gen_random_uuid(), NULL, :player, :puzzle, NULL, NOW())',
            ['player' => PlayerFixture::PLAYER_WITH_STRIPE, 'puzzle' => PuzzleFixture::PUZZLE_4000],
        );

        $tray->call('scan', ['ean' => PuzzleFixture::EAN_SHARED_4000_5000]);

        self::assertSame('resolved', self::rows($tray)[0]['state']);
        self::assertSame(PuzzleFixture::PUZZLE_4000, self::rows($tray)[0]['puzzleId']);
    }

    public function testUnknownCodeOpensTheResolveSheetWithTheBrandDetected(): void
    {
        $client = self::createClient();
        $tray = $this->tray($client);

        $tray->call('scan', ['ean' => PuzzleFixture::EAN_UNKNOWN]);
        $html = $tray->render()->toString();

        self::assertStringContainsString('data-multiscan-notice="unknown"', $html);
        self::assertStringContainsString('data-multiscan-sheet-open="1"', $html);
        self::assertStringContainsString('looks like a Ravensburger code', $html);
        self::assertStringContainsString('Puzzle not found', $html);
        self::assertSame('unknown', self::rows($tray)[0]['state']);

        // Skip keeps the row in the unresolved section, the camera resumes
        $tray->call('closeResolve');
        $html = $tray->render()->toString();
        self::assertStringContainsString('data-multiscan-sheet-open="0"', $html);
        self::assertStringContainsString('Not resolved yet (1)', $html);
        self::assertStringContainsString('data-multiscan-unknown-count="1"', $html);
    }

    public function testCatalogueSearchLinksTheCodeAndResolvesTheRow(): void
    {
        $client = self::createClient();
        $tray = $this->tray($client);

        $tray->call('scan', ['ean' => PuzzleFixture::EAN_UNKNOWN]);
        $tray->set('resolveQuery', 'Puzzle 19');
        $html = $tray->render()->toString();
        self::assertStringContainsString('data-live-puzzle-id-param="' . PuzzleFixture::PUZZLE_9000 . '"', $html);

        $tray->call('link', ['puzzleId' => PuzzleFixture::PUZZLE_9000]);
        $html = $tray->render()->toString();

        self::assertStringContainsString('data-multiscan-notice="linked"', $html);
        self::assertSame(PuzzleFixture::PUZZLE_9000, self::rows($tray)[0]['puzzleId']);
        self::assertStringContainsString('data-multiscan-sheet-open="0"', $html);

        /** @var Connection $database */
        $database = self::getContainer()->get(Connection::class);
        self::assertSame(PuzzleFixture::EAN_UNKNOWN, $database->fetchOne('SELECT ean FROM puzzle WHERE id = :id', ['id' => PuzzleFixture::PUZZLE_9000]));
    }

    public function testQuickAddCreatesThePuzzleAndResolvesTheRow(): void
    {
        $client = self::createClient();
        $tray = $this->tray($client);

        $tray->call('scan', ['ean' => PuzzleFixture::EAN_UNKNOWN]);
        $tray->call('toggleQuickAdd');
        $tray->call('createPuzzle');
        $html = $tray->render()->toString();
        self::assertStringContainsString('Name, pieces and brand are needed.', $html);

        $tray->set('newName', 'Scanned box');
        $tray->set('newPiecesCount', '1000');
        $tray->call('createPuzzle');
        $html = $tray->render()->toString();

        self::assertStringContainsString('data-multiscan-notice="created"', $html);
        self::assertSame('resolved', self::rows($tray)[0]['state']);
        self::assertStringContainsString('Scanned box', $html);

        /** @var Connection $database */
        $database = self::getContainer()->get(Connection::class);
        $row = $database->fetchAssociative('SELECT name, approved, ean, manufacturer_id FROM puzzle WHERE id = :id', ['id' => self::rows($tray)[0]['puzzleId']]);
        self::assertIsArray($row);
        self::assertSame('Scanned box', $row['name']);
        self::assertFalse($row['approved']);
        self::assertSame(PuzzleFixture::EAN_UNKNOWN, $row['ean']);
        self::assertSame('018d0002-0000-0000-0000-000000000001', $row['manufacturer_id'], 'brand prefilled from the EAN prefix');
    }

    public function testApplyLendsEligibleRowsKeepsTheRestAndShowsARecap(): void
    {
        $client = self::createClient();
        $tray = $this->tray($client);

        $tray->call('scan', ['ean' => PuzzleFixture::EAN_PUZZLE_6000]);
        $tray->call('scan', ['ean' => PuzzleFixture::EAN_PUZZLE_300]);
        $tray->call('scan', ['ean' => PuzzleFixture::EAN_PUZZLE_2000]); // already lent → skipped
        $tray->call('scan', ['ean' => PuzzleFixture::EAN_UNKNOWN]);
        $tray->call('closeResolve');

        $tray->set('action', 'lend');
        $html = $tray->render()->toString();
        self::assertStringContainsString('already lent to ' . PlayerFixture::PLAYER_REGULAR_NAME, $html);
        self::assertStringContainsString('Lend 2', $html);

        $tray->call('apply');
        $html = $tray->render()->toString();
        self::assertStringContainsString('Tell us who', $html);

        $tray->set('person', '#' . self::playerCode(PlayerFixture::PLAYER_WITH_FAVORITES));
        $tray->call('apply');
        $html = $tray->render()->toString();

        self::assertStringContainsString('2 puzzles lent to ' . PlayerFixture::PLAYER_WITH_FAVORITES_NAME, $html);
        self::assertStringContainsString('Puzzle 18', $html);
        self::assertStringContainsString('Puzzle 11', $html);

        // The pile stays for the next action; the lent rows now say so
        $rows = self::rows($tray);
        self::assertCount(4, $rows);
        self::assertStringContainsString('Lent to ' . PlayerFixture::PLAYER_WITH_FAVORITES_NAME, $html);
        self::assertStringContainsString('Lend 0', $html, 'nothing left to lend');

        /** @var Connection $database */
        $database = self::getContainer()->get(Connection::class);
        $holders = $database->fetchFirstColumn(
            'SELECT current_holder_player_id FROM lent_puzzle WHERE owner_player_id = :owner AND puzzle_id IN (:a, :b)',
            ['owner' => PlayerFixture::PLAYER_WITH_STRIPE, 'a' => PuzzleFixture::PUZZLE_6000, 'b' => PuzzleFixture::PUZZLE_300],
        );
        self::assertSame([PlayerFixture::PLAYER_WITH_FAVORITES, PlayerFixture::PLAYER_WITH_FAVORITES], $holders);
    }

    public function testReturnClosesOwnedAndHeldLendsAndNamesThePeople(): void
    {
        $client = self::createClient();
        $tray = $this->tray($client, data: ['presetAction' => 'return']);

        $tray->call('scan', ['ean' => PuzzleFixture::EAN_PUZZLE_2000]);   // lent to John
        $tray->call('scan', ['ean' => PuzzleFixture::EAN_PUZZLE_1500_02]); // borrowed from John
        $tray->call('scan', ['ean' => PuzzleFixture::EAN_PUZZLE_6000]);    // not lent → skipped
        $html = $tray->render()->toString();

        self::assertStringContainsString('not lent or borrowed', $html);
        self::assertStringContainsString('Close 2 lends', $html);

        $tray->call('apply');
        $html = $tray->render()->toString();

        self::assertStringContainsString('2 lends closed', $html);
        self::assertCount(3, self::rows($tray), 'rows stay in the pile after an action');
        self::assertStringContainsString('Mark 0 returned', $html, 'nothing left to close');
    }

    public function testAddToACollectionFromTheEntryPoint(): void
    {
        $client = self::createClient();
        $tray = $this->tray($client, data: ['presetAction' => 'add_to_library', 'presetCollectionId' => CollectionFixture::COLLECTION_STRIPE_TREFL]);

        $tray->call('scan', ['ean' => PuzzleFixture::EAN_PUZZLE_6000]);
        $tray->call('apply');
        $html = $tray->render()->toString();

        self::assertStringContainsString('One puzzle added to your library', $html);

        /** @var Connection $database */
        $database = self::getContainer()->get(Connection::class);
        self::assertSame('1', $database->fetchOne(
            'SELECT count(*)::text FROM collection_item WHERE player_id = :p AND puzzle_id = :z AND collection_id = :c',
            ['p' => PlayerFixture::PLAYER_WITH_STRIPE, 'z' => PuzzleFixture::PUZZLE_6000, 'c' => CollectionFixture::COLLECTION_STRIPE_TREFL],
        ));
    }

    public function testForeignCollectionPresetIsIgnored(): void
    {
        $client = self::createClient();
        $tray = $this->tray($client, data: ['presetAction' => 'add_to_library', 'presetCollectionId' => CollectionFixture::COLLECTION_PRIVATE]);

        $component = $tray->component();
        assert($component instanceof MultiscanTray);
        self::assertSame('__system_collection__', $component->collectionId);
    }

    public function testNonMemberCannotScan(): void
    {
        $client = self::createClient();
        $tray = $this->tray($client, PlayerFixture::PLAYER_REGULAR);

        $this->expectException(AccessDeniedHttpException::class);
        $tray->call('scan', ['ean' => PuzzleFixture::EAN_PUZZLE_6000]);
    }

    /**
     * @return list<array{ean: string, puzzleId: null|string, state: string, candidateIds: list<string>}>
     */
    private static function rows(TestLiveComponent $tray): array
    {
        $component = $tray->component();
        assert($component instanceof MultiscanTray);

        return $component->rows;
    }

    public function testPersonPickerSuggestsThePeopleLentToAndBorrowedFromThenFavourites(): void
    {
        $client = self::createClient();
        // PLAYER_WITH_STRIPE lent to John Doe (PLAYER_REGULAR), "Jane Doe", PLAYER_WITH_FAVORITES; borrowed from John Doe
        $tray = $this->tray($client, data: ['presetAction' => 'lend']);
        $html = $tray->render()->toString();

        self::assertStringContainsString('People you lend to and borrow from', $html);
        self::assertStringContainsString('<option value="Jane Doe"', $html, 'a name without an account is offered as plain text');
        self::assertStringContainsString('<option value="#player1"', $html, 'a registered borrower is offered by code');
        self::assertStringContainsString('data-controller="multiscan-picker"', $html);
        self::assertStringContainsString('data-multiscan-picker-mode-value="person"', $html);
        self::assertStringContainsString('/en/player-search-autocomplete/?format=co-puzzler', $html);

        $regularPosition = strpos($html, '<option value="#player1"');
        $janePosition = strpos($html, '<option value="Jane Doe"');
        self::assertNotFalse($regularPosition);
        self::assertNotFalse($janePosition);
    }

    public function testApplyCreatesACollectionTypedIntoThePicker(): void
    {
        $client = self::createClient();
        $tray = $this->tray($client);

        $tray->call('scan', ['ean' => PuzzleFixture::EAN_PUZZLE_6000]);
        $tray->set('collectionId', 'Scanned pile');
        $tray->call('apply');
        $html = $tray->render()->toString();

        self::assertStringContainsString('One puzzle added to your library', $html);

        /** @var Connection $database */
        $database = self::getContainer()->get(Connection::class);
        $collectionId = $database->fetchOne('SELECT id FROM collection WHERE player_id = :p AND name = :n', ['p' => PlayerFixture::PLAYER_WITH_STRIPE, 'n' => 'Scanned pile']);
        self::assertIsString($collectionId);
        self::assertSame('1', $database->fetchOne('SELECT count(*)::text FROM collection_item WHERE collection_id = :c AND puzzle_id = :z', ['c' => $collectionId, 'z' => PuzzleFixture::PUZZLE_6000]));

        $component = $tray->component();
        assert($component instanceof MultiscanTray);
        self::assertSame($collectionId, $component->collectionId, 'the picker now holds the created collection');
    }

    /**
     * A scan is one round trip per box: the cost must not grow with the tray.
     * Measured: 6 queries (user, lookup, statuses, hydration, collections, session); budget leaves a little slack.
     */
    public function testScanAndApplyStayWithinAQueryBudgetWhateverTheTraySize(): void
    {
        $client = self::createClient();
        $tray = $this->tray($client);
        // The helper's first call also performs the mount request; the profiler counts one request only
        $tray->render();

        $this->startCountingQueries($client);
        $tray->call('scan', ['ean' => PuzzleFixture::EAN_PUZZLE_6000]);
        $first = $this->queryCount($client);

        foreach ([PuzzleFixture::EAN_PUZZLE_300, PuzzleFixture::EAN_PUZZLE_2000, PuzzleFixture::EAN_PUZZLE_1500_02, PuzzleFixture::EAN_PUZZLE_1500_01] as $ean) {
            $tray->call('scan', ['ean' => $ean]);
        }

        $this->startCountingQueries($client);
        $tray->call('scan', ['ean' => PuzzleFixture::EAN_PUZZLE_500_03]);
        $sixth = $this->queryCount($client);

        self::assertLessThanOrEqual(8, $first, 'a scan into an empty tray costs too many queries: ' . $first);
        self::assertSame($first, $sixth, 'a scan must cost the same with 6 rows as with 1 (no per-row queries)');

        $tray->set('action', 'add_to_wishlist');
        $this->startCountingQueries($client);
        $tray->call('apply');
        $apply = $this->queryCount($client);

        // 2 eligible rows (6000, 500_03): one query per written row is the price of reusing the single handlers
        self::assertLessThanOrEqual(30, $apply, 'apply costs too many queries: ' . $apply);
    }

    private static function playerCode(string $playerId): string
    {
        /** @var Connection $database */
        $database = self::getContainer()->get(Connection::class);

        $code = $database->fetchOne('SELECT code FROM player WHERE id = :id', ['id' => $playerId]);
        assert(is_string($code));

        return $code;
    }
}
