<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Controller;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\CollectionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\OverridesFeatureFlagEnv;
use SpeedPuzzling\Web\Tests\QueryCountAssertions;
use SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence\ImageEstimates\GateV2Numbers;
use SpeedPuzzling\Web\Tests\TestingLogin;
use SpeedPuzzling\Web\Value\ImageKind;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use Symfony\Bundle\FrameworkBundle\KernelBrowser;
use Symfony\Bundle\FrameworkBundle\Test\WebTestCase;
use Symfony\Component\DomCrawler\Crawler;

/**
 * Personal time estimates on the pages (option C, docs/features/insights/estimates.md): behind INSIGHTS_PUBLIC (admins
 * always). While the flag is off a non-admin sees exactly today's pages - no estimate markup, no sheet, not one statement
 * more; with it on members get the puzzle page card and one line per list row (one statement per page), free players
 * the lock on the puzzle page and today's rows elsewhere, and the sheet sits on the page once.
 */
final class PersonalEstimatesDisplayTest extends WebTestCase
{
    use OverridesFeatureFlagEnv;
    use QueryCountAssertions;

    private const string FLAG = 'INSIGHTS_PUBLIC';
    private const string MEMBER = PlayerFixture::PLAYER_WITH_STRIPE;
    private const string FREE = PlayerFixture::PLAYER_REGULAR;

    /** What only the estimates read */
    private const array ESTIMATE_SQL = ['listed AS (', 'image_estimate_evaluation', 'collection_item own'];

    protected function tearDown(): void
    {
        $this->restoreFeatureFlagEnv();

        parent::tearDown();
    }

    public function testWhileTheFlagIsOffAMemberSeesTodaysPagesAndNoStatementMore(): void
    {
        $browser = $this->browser(flag: false, player: self::MEMBER);

        foreach ($this->pages() as $name => $url) {
            $this->startCountingQueries($browser);
            $page = $browser->request('GET', $url);
            self::assertResponseIsSuccessful($name);

            self::assertCount(0, $page->filter('[data-testid="estimate-card"], [data-testid="estimate-line"], [data-testid="estimate-sheet"], [data-testid="estimate-chip"]'), "{$name}: nothing new");
            self::assertCount(0, $page->filter('.diff-corner--estimated'), "{$name}: no estimated corner");
            $this->assertNoEstimateStatement($browser, $name);
        }

        // The puzzle page keeps its own prediction in the Insights panel
        $page = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_02);
        self::assertGreaterThan(0, $page->filter('#puzzleInsights .prediction-window')->count());
    }

    public function testWhileTheFlagIsOffAFreePlayerSeesNoLock(): void
    {
        $browser = $this->browser(flag: false, player: self::FREE);
        $this->startCountingQueries($browser);

        $page = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_02);

        self::assertResponseIsSuccessful();
        self::assertCount(0, $page->filter('[data-testid="estimate-card"]'));
        $this->assertNoEstimateStatement($browser, 'puzzle page');
    }

    public function testAnAdminAlwaysSeesTheEstimates(): void
    {
        $browser = $this->browser(flag: false, player: PlayerFixture::PLAYER_ADMIN);

        $page = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_02);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-testid="estimate-card"]'));
        self::assertCount(1, $page->filter('[data-testid="estimate-sheet"]'), 'the sheet, once per page');
    }

    public function testAMemberGetsTheCardWithTheSameTimeTheInsightsPanelShowedBefore(): void
    {
        $browser = $this->browser(flag: true, player: self::MEMBER);

        $page = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_02);

        self::assertResponseIsSuccessful();
        $card = $page->filter('[data-testid="estimate-card"]');
        self::assertCount(1, $card);
        self::assertStringContainsString('for you', $card->filter('[data-testid="estimate-card-time"]')->text());
        self::assertStringContainsString('≈', $card->text());
        self::assertStringContainsString('half of solves land inside', $card->text());
        self::assertStringContainsString('8 in 10 solves', $card->text());
        // The panel leaves its own prediction out - one time per puzzle on the page
        self::assertCount(0, $page->filter('#puzzleInsights .prediction-window'));
        self::assertCount(0, $page->filter('[data-testid="estimate-card-locked"]'));
    }

    public function testAnEstimatedTierIsDashedWithAQuestionMarkAndSaysEstimated(): void
    {
        // PUZZLE_500_05: 2 difficulty indices - not rated yet, estimated from its first solves
        $browser = $this->browser(flag: true, player: self::MEMBER);

        $page = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_05);

        self::assertResponseIsSuccessful();
        $card = $page->filter('[data-testid="estimate-card"]');
        self::assertCount(1, $card);
        self::assertStringContainsString('est-card--estimated', (string) $card->attr('class'));
        $chip = $card->filter('[data-testid="estimate-chip"]');
        self::assertCount(1, $chip);
        self::assertNotNull($chip->attr('data-estimated'));
        self::assertStringContainsString('est-chip--estimated', (string) $chip->attr('class'));
        self::assertStringEndsWith('?', trim($chip->filter('.est-chip__label')->text()));
        self::assertStringContainsString('(estimated)', $chip->filter('.visually-hidden')->text());
        // The head's chip too, in place of "not enough data"
        self::assertCount(1, $page->filter('.puzzle-head-difficulty [data-testid="estimate-chip"][data-estimated]'));
    }

    public function testAFreePlayerGetsTheLockOnThePuzzlePageAndTodaysRowsElsewhere(): void
    {
        $browser = $this->browser(flag: true, player: self::FREE);

        $page = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_02);
        self::assertResponseIsSuccessful();
        $lock = $page->filter('[data-testid="estimate-card-locked"]');
        self::assertCount(1, $lock);
        self::assertStringContainsString('≈ ? for you', $lock->text());
        self::assertSame('#membersExclusiveModal', $lock->attr('data-bs-target'));
        self::assertCount(0, $page->filter('[data-testid="estimate-card-time"], [data-testid="estimate-sheet"]'), 'no members-only content');

        $this->startCountingQueries($browser);
        $list = $browser->request('GET', '/en/wish-list/' . self::FREE);
        self::assertResponseIsSuccessful();
        self::assertCount(0, $list->filter('[data-testid="estimate-line"]'));
        $this->assertNoEstimateStatement($browser, 'wishlist of a free player');
    }

    public function testAMemberWhoSwitchedPredictionsOffGetsNoTimes(): void
    {
        $this->connection()->executeStatement('UPDATE player SET time_predictions_opted_out = true WHERE id = :id', ['id' => self::MEMBER]);
        $browser = $this->browser(flag: true, player: self::MEMBER);

        $page = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_02);

        self::assertResponseIsSuccessful();
        self::assertCount(1, $page->filter('[data-testid="estimate-card-opted-out"]'));
        self::assertCount(0, $page->filter('[data-testid="estimate-card-time"], [data-testid="estimate-line"]'));
    }

    public function testListsShowOneLinePerRowForOneStatementMore(): void
    {
        $counts = [];

        foreach ([false, true] as $flag) {
            $browser = $this->browser(flag: $flag, player: self::MEMBER);

            foreach (['system collection', 'wishlist', 'unsolved', 'marketplace'] as $name) {
                // Once unprofiled: a session's first request does one-off work
                $browser->request('GET', $this->pages()[$name]);
                $this->startCountingQueries($browser);
                $page = $browser->request('GET', $this->pages()[$name]);
                self::assertResponseIsSuccessful();
                $counts[$name][$flag ? 'on' : 'off'] = $this->queryCount($browser);

                if ($flag) {
                    $lines = $page->filter('[data-testid="estimate-line"]');
                    self::assertGreaterThan(0, $lines->count(), "{$name}: estimate lines");
                    $this->assertLinesAreWellFormed($lines, $name);
                    self::assertCount(1, $page->filter('[data-testid="estimate-sheet"]'), "{$name}: one sheet");
                }
            }
        }

        foreach ($counts as $name => $count) {
            self::assertSame($count['off'] + 1, $count['on'], "{$name}: one statement for the whole list");
        }
    }

    public function testAPuzzleNobodyHasSolvedShowsThePictureEstimateOnlyWhereItsGatePassed(): void
    {
        $browser = $this->browser(flag: true, player: self::MEMBER);
        $this->storePictureEstimate($browser, PuzzleFixture::PUZZLE_500_04, 0.3, 'box_front');
        $url = '/en/puzzle/' . PuzzleFixture::PUZZLE_500_04;

        // No gate record yet: closed - no time, and the prior alone is never shown either
        $page = $browser->request('GET', $url);
        self::assertCount(1, $page->filter('[data-testid="estimate-card-none"]'));
        self::assertCount(0, $page->filter('[data-testid="estimate-card-time"]'));

        // 500 pieces × box passed its gate
        $this->storeGateRecord($browser, passing: true);
        $this->startCountingQueries($browser);
        $page = $browser->request('GET', $url);
        $card = $page->filter('[data-testid="estimate-card"]');
        self::assertCount(1, $card->filter('[data-testid="estimate-card-time"]'));
        self::assertStringContainsString('est-card--estimated', (string) $card->attr('class'));
        self::assertStringContainsString('from the picture', $card->text());
        self::assertCount(1, $card->filter('[data-testid="estimate-chip"][data-estimated]'));
        $gateReads = array_filter($this->executedSql($browser), static fn (string $sql): bool => str_contains($sql, 'image_estimate_evaluation'));
        self::assertCount(1, $gateReads, 'the gate record, once per request');

        // A failing record closes it again
        $this->storeGateRecord($browser, passing: false);
        $page = $browser->request('GET', $url);
        self::assertCount(1, $page->filter('[data-testid="estimate-card-none"]'));
    }

    public function testAPassingRecordOfAnotherEstimateGenerationKeepsThePictureClosed(): void
    {
        // The record measured generation A ("… 0.9.0"); the puzzle's estimate is generation B ("… 1.0.0") - a derive
        // model change nobody has evaluated yet: closed, whatever A's numbers say
        $browser = $this->browser(flag: true, player: self::MEMBER);
        $this->storePictureEstimate($browser, PuzzleFixture::PUZZLE_500_04, 0.3, 'box_front');
        $this->storeGateRecord($browser, passing: true, generation: 'ridge-siglip2-dinov2 0.9.0');

        $page = $browser->request('GET', '/en/puzzle/' . PuzzleFixture::PUZZLE_500_04);

        self::assertCount(1, $page->filter('[data-testid="estimate-card-none"]'));
        self::assertCount(0, $page->filter('[data-testid="estimate-card-time"]'));
    }

    public function testASecretPuzzleOnAListHasNoEstimate(): void
    {
        $browser = $this->browser(flag: true, player: self::MEMBER);
        $url = $this->pages()['system collection'];

        $page = $browser->request('GET', $url);
        $withLine = $page->filter('[data-testid="estimate-line"]')->each(static fn (Crawler $line): string => (string) $line->closest('[data-puzzle-id]')?->attr('data-puzzle-id'));
        self::assertNotSame([], $withLine);
        $secret = $withLine[0];

        /** @var Connection $connection */
        $connection = $browser->getContainer()->get(Connection::class);
        $connection->executeStatement("UPDATE puzzle SET hide_until = " . TestClock::SQL_NOW . " + INTERVAL '2 days' WHERE id = :id", ['id' => $secret]);

        $page = $browser->request('GET', $url);
        self::assertCount(0, $page->filter('[data-puzzle-id="' . $secret . '"] [data-testid="estimate-line"]'), 'no estimate while the puzzle is secret');
    }

    /**
     * The statements the estimates cost with the flag on (estimates.md "Cost"): the puzzle page = 1 estimate statement
     * in place of GetPlayerPrediction's + the collection rank when the puzzle is in a collection (+ the gate record only
     * for a k = 0 picture candidate - none here); the Picker = 1 in place of GetPlayerPredictions' for the cards; a custom
     * collection = exactly one more than with the flag off.
     */
    public function testTheStatementBudgetsWithTheFlagOn(): void
    {
        $counts = [];
        $sql = [];
        $urls = [
            'puzzle page' => '/en/puzzle/' . PuzzleFixture::PUZZLE_500_02,
            'picker' => '/en/what-to-solve-next?seed=6c6c6c6c&pieces=500',
            'custom collection' => '/en/collection/' . CollectionFixture::COLLECTION_STRIPE_TREFL,
        ];

        foreach ([false, true] as $flag) {
            $browser = $this->browser(flag: $flag, player: self::MEMBER);

            foreach ($urls as $name => $url) {
                $browser->request('GET', $url);
                $this->startCountingQueries($browser);
                $browser->request('GET', $url);
                self::assertResponseIsSuccessful($name);
                $counts[$name][$flag ? 'on' : 'off'] = $this->queryCount($browser);
                $sql[$name][$flag ? 'on' : 'off'] = $this->executedSql($browser);
            }
        }

        $count = self::statementsWith(...);
        $predictionStatements = self::predictionStatements(...);

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);
        $inCollection = $connection->fetchOne('SELECT EXISTS (SELECT 1 FROM collection_item WHERE player_id = :player AND puzzle_id = :puzzle)', ['player' => self::MEMBER, 'puzzle' => PuzzleFixture::PUZZLE_500_02]) === true ? 1 : 0;

        // Puzzle page
        $on = $sql['puzzle page']['on'];
        self::assertSame(1, $count($on, 'listed AS ('), 'one estimate statement');
        self::assertSame($inCollection, $count($on, 'collection_item own'), 'the collection rank only for a puzzle in a collection');
        self::assertSame(0, $count($on, 'image_estimate_evaluation'), 'no gate record without a k = 0 picture candidate');
        self::assertSame(0, $predictionStatements($on), 'no GetPlayerPrediction statement while the card shows');
        $off = $predictionStatements($sql['puzzle page']['off']);
        self::assertGreaterThanOrEqual(2, $off);
        self::assertSame($counts['puzzle page']['off'] - $off + 1 + $inCollection, $counts['puzzle page']['on'], sprintf('puzzle page: off %d (%d prediction statements), on %d, in collection %d', $counts['puzzle page']['off'], $off, $counts['puzzle page']['on'], $inCollection));

        // Picker
        $on = $sql['picker']['on'];
        self::assertSame(1, $count($on, 'listed AS ('));
        self::assertSame(0, $predictionStatements($on), 'no GetPlayerPredictions statement for the cards');
        $off = $predictionStatements($sql['picker']['off']);
        self::assertSame($counts['picker']['off'] - $off + 1, $counts['picker']['on'], sprintf("picker: off %d (%d prediction statements), on %d\n%s\n---\n%s", $counts['picker']['off'], $off, $counts['picker']['on'], implode("\n", array_map(static fn (string $q): string => substr($q, 0, 90), $sql['picker']['off'])), implode("\n", array_map(static fn (string $q): string => substr($q, 0, 90), $on))));

        // Custom collection
        self::assertSame($counts['custom collection']['off'] + 1, $counts['custom collection']['on']);
    }

    public function testTheEstimateLineStandsInForThePredictionPillWithoutLoadingThePredictionsTwice(): void
    {
        $browser = $this->browser(flag: true, player: self::MEMBER);
        /** @var Connection $connection */
        $connection = $browser->getContainer()->get(Connection::class);
        $connection->executeStatement("UPDATE player SET collection_display_mode = 'times_predictions' WHERE id = :id", ['id' => self::MEMBER]);
        $url = $this->pages()['system collection'];

        $browser->request('GET', $url);
        $this->startCountingQueries($browser);
        $page = $browser->request('GET', $url);

        self::assertResponseIsSuccessful();
        self::assertGreaterThan(0, $page->filter('[data-testid="estimate-line"]')->count());
        self::assertGreaterThan(0, $page->filter('.collection-my-times')->count(), 'the display mode still shows the own times');
        self::assertCount(0, $page->filter('.collection-prediction'), 'the line stands in for the pill');

        foreach ($this->executedSql($browser) as $statement) {
            self::assertStringNotContainsString('json_object_agg(from_attempt, median_ratio)', $statement, 'GetPlayerPredictions is not loaded a second time');
        }
    }

    public function testThePickerCardCarriesTheLine(): void
    {
        $browser = $this->browser(flag: true, player: self::MEMBER);

        $page = $browser->request('GET', '/en/what-to-solve-next?seed=6c6c6c6c&pieces=500');

        self::assertResponseIsSuccessful();
        $cards = $page->filter('.puzzle-picker-card');
        self::assertGreaterThan(0, $cards->count());
        self::assertCount($cards->filter('.puzzle-picker-estimate')->count() + $cards->filter('.puzzle-picker-prediction-unavailable')->count(), $cards);
        self::assertCount(0, $cards->filter('.puzzle-picker-prediction'), 'the old prediction row gives way');
    }

    /**
     * @return array<string, string>
     */
    private function pages(): array
    {
        return [
            'puzzle page' => '/en/puzzle/' . PuzzleFixture::PUZZLE_500_02,
            'unrated puzzle page' => '/en/puzzle/' . PuzzleFixture::PUZZLE_500_05,
            'system collection' => '/en/puzzle-collection/' . self::MEMBER,
            'wishlist' => '/en/wish-list/' . self::MEMBER,
            'unsolved' => '/en/unsolved-puzzles/' . self::MEMBER,
            'picker' => '/en/what-to-solve-next?seed=6c6c6c6c&pieces=500',
            'marketplace' => '/en/marketplace',
        ];
    }

    private function assertLinesAreWellFormed(Crawler $lines, string $name): void
    {
        $lines->each(static function (Crawler $line) use ($name): void {
            self::assertSame('button', $line->nodeName(), "{$name}: a button");
            self::assertSame('button', $line->attr('type'));
            self::assertSame('estimate-sheet', $line->attr('data-controller'));
            self::assertStringStartsWith('≈', trim($line->filter('.est-line__time')->text()));
            self::assertNotSame('', (string) $line->attr('data-estimate-sheet-time-value'));
            self::assertNotSame('', (string) $line->attr('data-estimate-sheet-basis-value'));

            if ($line->filter('[data-testid="estimate-chip"][data-estimated]')->count() > 0) {
                self::assertStringContainsString('(estimated)', $line->text(), "{$name}: an estimated tier says so");
            }
        });
    }

    /**
     * @param list<string> $statements
     */
    private static function statementsWith(array $statements, string $marker): int
    {
        return count(array_filter($statements, static fn (string $statement): bool => str_contains($statement, $marker)));
    }

    /**
     * GetPlayerPrediction (one puzzle) and GetPlayerPredictions (many): what the estimate statement replaces. The logged
     * SQL carries positional placeholders, so no marker names a parameter.
     *
     * @param list<string> $statements
     */
    private static function predictionStatements(array $statements): int
    {
        $markers = ['AS solved_at', 'SELECT pieces_count FROM puzzle WHERE id =', 'AND from_attempt =', 'global_improvement_ratio WHERE pieces_count', 'AS model_parameters', 'json_object_agg(from_attempt, median_ratio)', 'ORDER BY pst.puzzle_id, COALESCE', 'own_pace AS ('];

        return count(array_filter($statements, static function (string $statement) use ($markers): bool {
            if (str_contains($statement, 'listed AS (')) {
                return false;
            }

            foreach ($markers as $marker) {
                if (str_contains($statement, $marker)) {
                    return true;
                }
            }

            return false;
        }));
    }

    private function storePictureEstimate(KernelBrowser $browser, string $puzzleId, float $logDifficulty, string $artworkKind): void
    {
        /** @var Connection $connection */
        $connection = $browser->getContainer()->get(Connection::class);
        $image = 'box-' . $puzzleId . '.jpg';
        $fingerprint = PuzzleImageFingerprint::of($image);
        $connection->executeStatement('UPDATE puzzle SET image = :image WHERE id = :id', ['image' => $image, 'id' => $puzzleId]);
        $connection->executeStatement(
            "INSERT INTO puzzle_difficulty_estimate (puzzle_id, model, model_version, log_difficulty, log_p10, log_p90, out_of_fold, training_size, image_fingerprint, computed_at, received_at)
            VALUES (:puzzle, 'ridge-siglip2-dinov2', '1.0.0', :log, :p10, :p90, true, 4979, :fingerprint, " . TestClock::SQL_NOW . ", " . TestClock::SQL_NOW . ")",
            ['puzzle' => $puzzleId, 'log' => $logDifficulty, 'p10' => $logDifficulty - 0.2, 'p90' => $logDifficulty + 0.25, 'fingerprint' => $fingerprint],
        );
        $connection->executeStatement(
            "INSERT INTO puzzle_image_analysis (id, puzzle_id, analyzer, analyzer_version, image_fingerprint, result, analyzed_at, received_at)
            VALUES (:id, :puzzle, 'artwork', '1.0.0', :fingerprint, CAST(:result AS jsonb), NULL, " . TestClock::SQL_NOW . ")",
            ['id' => Uuid::uuid7()->toString(), 'puzzle' => $puzzleId, 'fingerprint' => $fingerprint, 'result' => json_encode(['kind' => $artworkKind], JSON_THROW_ON_ERROR)],
        );
    }

    private function storeGateRecord(KernelBrowser $browser, bool $passing, string $generation = 'ridge-siglip2-dinov2 1.0.0'): void
    {
        /** @var Connection $connection */
        $connection = $browser->getContainer()->get(Connection::class);
        $group = ['spearman' => $passing ? 0.72 : 0.41] + GateV2Numbers::passing(PiecesGroup::FiveHundred->value, ImageKind::Box->value, $generation);
        $connection->executeStatement(
            "INSERT INTO image_estimate_evaluation (id, evaluated_at, model_version, newer_than, measured_min_indices, groups, overall, by_k, gate_version)
            VALUES (:id, " . TestClock::SQL_NOW . " + (:offset * INTERVAL '1 second'), :version, " . TestClock::SQL_NOW . " - INTERVAL '1 year', 10, CAST(:groups AS jsonb), '{}', '[]', 2)",
            ['id' => Uuid::uuid7()->toString(), 'offset' => $passing ? 1 : 2, 'version' => TimePredictionCalculator::MODEL_VERSION, 'groups' => json_encode([$group], JSON_THROW_ON_ERROR)],
        );
    }

    private function assertNoEstimateStatement(KernelBrowser $browser, string $name): void
    {
        foreach ($this->executedSql($browser) as $sql) {
            foreach (self::ESTIMATE_SQL as $marker) {
                self::assertStringNotContainsString($marker, $sql, "{$name}: no estimate statement while hidden");
            }
        }
    }

    private function browser(bool $flag, string $player): KernelBrowser
    {
        $this->overrideFeatureFlagEnv(self::FLAG, $flag);
        self::ensureKernelShutdown();
        $browser = self::createClient();
        $browser->getContainer()->get(PuzzleIntelligenceRecalculator::class)->recalculate();
        TestingLogin::asPlayer($browser, $player);

        return $browser;
    }

    private function connection(): Connection
    {
        if (self::$booted === false) {
            self::bootKernel();
        }

        /** @var Connection $connection */
        $connection = self::getContainer()->get(Connection::class);

        return $connection;
    }
}
