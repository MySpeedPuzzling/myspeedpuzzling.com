<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use SpeedPuzzling\Web\Tests\TestClock;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\RecordPuzzleDifficultyEstimates;
use SpeedPuzzling\Web\Query\GetPersonalEstimates;
use SpeedPuzzling\Web\Query\GetPlayerPrediction;
use SpeedPuzzling\Web\Results\PersonalEstimate;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence\FitsThePredictionModel;
use SpeedPuzzling\Web\Value\DifficultySource;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\EstimateBasis;
use SpeedPuzzling\Web\Value\ImageKind;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\PuzzleDifficultyEstimateInput;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use Symfony\Bridge\Doctrine\Middleware\Debug\DebugDataHolder;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;

/**
 * The personal estimates' read model (docs/features/insights/estimates.md): one statement for a whole list, the same
 * time as every other surface shows for the same player and puzzle (GetPlayerPrediction), the picture-only estimate at
 * k = 0 only where the gate says so, never anything for a secret puzzle, and the community's time by piece count for a
 * player without any pace. On a fitted model (FitsThePredictionModel).
 */
final class GetPersonalEstimatesTest extends KernelTestCase
{
    use FitsThePredictionModel;

    private const array PLAYERS = [
        PlayerFixture::PLAYER_REGULAR,
        PlayerFixture::PLAYER_PRIVATE,
        PlayerFixture::PLAYER_ADMIN,
        PlayerFixture::PLAYER_WITH_FAVORITES,
        PlayerFixture::PLAYER_WITH_STRIPE,
    ];

    /** PUZZLE_500_04: nobody has solved it - k = 0, the picture's puzzle */
    private const float LOG_500_04 = 0.3;

    private Connection $database;

    private GetPersonalEstimates $estimates;

    private GetPlayerPrediction $single;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->database = $container->get(Connection::class);
        $container->get(PuzzleIntelligenceRecalculator::class)->recalculate();
        $this->fitPredictionModel();

        $this->estimates = $container->get(GetPersonalEstimates::class);
        $this->single = $container->get(GetPlayerPrediction::class);
    }

    public function testEveryPuzzleShowsTheTimeEveryOtherSurfaceShows(): void
    {
        $puzzleIds = $this->allPuzzleIds();
        /** @var list<string> $secret */
        $secret = $this->database->fetchFirstColumn('SELECT id FROM puzzle WHERE hide_until > ' . TestClock::SQL_NOW . ' OR hide_image_until > ' . TestClock::SQL_NOW);
        self::assertNotSame([], $secret, 'the fixtures hold a secret puzzle');
        $bases = [];

        foreach (self::PLAYERS as $playerId) {
            $estimates = $this->estimates->forPuzzles($playerId, $puzzleIds, static fn (): bool => true);

            foreach ($puzzleIds as $puzzleId) {
                $single = $this->single->forPuzzle($playerId, $puzzleId);
                $estimate = $estimates[$puzzleId] ?? null;

                if (in_array($puzzleId, $secret, true)) {
                    self::assertNull($estimate, "{$playerId} × {$puzzleId}: secret");

                    continue;
                }

                if ($single === null) {
                    // Nothing shown elsewhere: no estimate (no picture estimate exists in the fixtures)
                    self::assertNull($estimate, "{$playerId} × {$puzzleId}");

                    continue;
                }

                self::assertNotNull($estimate, "{$playerId} × {$puzzleId}");
                self::assertEquals($single, $estimate->prediction, "{$playerId} × {$puzzleId}: the same time as the puzzle page");
                self::assertSame($single->isPersonalized, $estimate->basis === EstimateBasis::YourSolves);
                $bases[$estimate->basis->value] = true;
            }
        }

        self::assertArrayHasKey('your_solves', $bases);
        self::assertArrayHasKey('first_solves', $bases);
    }

    public function testAWholeListIsOneStatement(): void
    {
        $listed = [...$this->allPuzzleIds(), PuzzleFixture::PUZZLE_500_01];
        /** @var DebugDataHolder $holder */
        $holder = self::getContainer()->get('doctrine.debug_data_holder');
        $holder->reset();

        $estimates = $this->estimates->forPuzzles(PlayerFixture::PLAYER_WITH_STRIPE, $listed, static fn (): bool => true);

        self::assertNotSame([], $estimates);
        $queries = $holder->getData()['default'] ?? [];
        self::assertCount(1, is_array($queries) ? $queries : [], 'one statement, however long the list');
    }

    public function testAPuzzleNobodyHasSolvedShowsThePictureEstimateOnlyWhereTheGatePasses(): void
    {
        $this->storeEstimate(PuzzleFixture::PUZZLE_500_04, self::LOG_500_04, 'box_front');
        $asked = [];
        $gate = static function (PiecesGroup $group, ImageKind $kind, string $generation) use (&$asked): bool {
            $asked[] = [$group, $kind, $generation];

            return true;
        };

        $estimate = $this->estimates->forPuzzles(PlayerFixture::PLAYER_WITH_STRIPE, [PuzzleFixture::PUZZLE_500_04], $gate)[PuzzleFixture::PUZZLE_500_04] ?? null;

        self::assertSame([[PiecesGroup::FiveHundred, ImageKind::Box, 'ridge-siglip2-dinov2 1.0.0']], $asked, 'the gate is asked for the size group × image kind × estimate generation');
        self::assertInstanceOf(PersonalEstimate::class, $estimate);
        self::assertSame(EstimateBasis::Picture, $estimate->basis);
        self::assertTrue($estimate->isEstimated());
        self::assertSame(0, $estimate->solvedIndices);
        // The tier is the picture's, marked as estimated
        self::assertSame(DifficultyTier::fromScore(exp(self::LOG_500_04)), $estimate->tier);
        self::assertTrue($estimate->tierEstimated);
        // The number the stored `image` side row carries: pace × drift × exp(estimate), its window from the estimate
        self::assertNotNull($estimate->prediction);
        self::assertSame(DifficultySource::Image, $estimate->prediction->difficultySource);
        $sideBySide = $this->single->sideBySide(PlayerFixture::PLAYER_WITH_STRIPE, PuzzleFixture::PUZZLE_500_04);
        self::assertSame($sideBySide->image?->result?->predictedSeconds, $estimate->prediction->predictedSeconds);

        // Gate closed: nothing - and the statistical prior-only number is never shown either
        $closed = $this->estimates->forPuzzles(PlayerFixture::PLAYER_WITH_STRIPE, [PuzzleFixture::PUZZLE_500_04], static fn (): bool => false);
        self::assertSame([], $closed);
    }

    public function testThePictureNeverReplacesAPredictionFromSolves(): void
    {
        // PUZZLE_500_05 has 2 difficulty indices: the shown prediction is the statistical one, shrunk toward brand ×
        // pieces - a picture estimate does not move it (the image prior reaches shown predictions only with the launch
        // MODEL_VERSION bump), and the gate is never asked
        $this->storeEstimate(PuzzleFixture::PUZZLE_500_05, 1.2, 'box_front');
        $before = $this->single->forPuzzle(PlayerFixture::PLAYER_REGULAR, PuzzleFixture::PUZZLE_500_05);

        $estimate = $this->estimates->forPuzzles(PlayerFixture::PLAYER_REGULAR, [PuzzleFixture::PUZZLE_500_05], static function (): bool {
            self::fail('the gate is only asked for puzzles nobody has measured');
        })[PuzzleFixture::PUZZLE_500_05] ?? null;

        self::assertNotNull($estimate);
        self::assertSame(EstimateBasis::FirstSolves, $estimate->basis);
        self::assertEquals($before, $estimate->prediction);
        self::assertTrue($estimate->tierEstimated, 'not rated yet: the shrunk difficulty, marked as estimated');
        self::assertNotNull($estimate->tier);
    }

    public function testASecretPuzzleNeverHasAnEstimate(): void
    {
        $this->storeEstimate(PuzzleFixture::PUZZLE_500_04, self::LOG_500_04, 'box_front');
        $listed = [PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_500_02, PuzzleFixture::PUZZLE_500_04, PuzzleFixture::PUZZLE_500_05];
        self::assertCount(4, $this->estimates->forPuzzles(PlayerFixture::PLAYER_REGULAR, $listed, static fn (): bool => true));

        $this->database->executeStatement("UPDATE puzzle SET hide_until = " . TestClock::SQL_NOW . " + INTERVAL '3 days' WHERE id IN (:a, :b)", ['a' => PuzzleFixture::PUZZLE_500_02, 'b' => PuzzleFixture::PUZZLE_500_04]);
        $this->database->executeStatement("UPDATE puzzle SET hide_image_until = " . TestClock::SQL_NOW . " + INTERVAL '3 days' WHERE id = :id", ['id' => PuzzleFixture::PUZZLE_500_05]);

        $estimates = $this->estimates->forPuzzles(PlayerFixture::PLAYER_REGULAR, $listed, static fn (): bool => true);

        // A solved one (personal), a picture one and a shrunk one - all gone while secret
        self::assertSame([PuzzleFixture::PUZZLE_500_01], array_keys($estimates));
    }

    public function testAPlayerWithoutAnyPaceGetsTheCommunityTimeByPieceCount(): void
    {
        $newcomer = Uuid::uuid7()->toString();
        $this->database->executeStatement("INSERT INTO player (id, code, name, registered_at, is_private) VALUES (:id, 'newcomer6c', 'Newcomer', " . TestClock::SQL_NOW . ", false)", ['id' => $newcomer]);
        // 20 puzzlers with a direct 500-piece pace (the fixtures have a handful): 3000 s … 3950 s, median 3475 s
        $this->database->executeStatement(
            "INSERT INTO player (id, code, name, registered_at, is_private)
            SELECT CAST('019b0c00-0000-7000-8000-' || lpad(n::text, 12, '0') AS uuid), 'pace6c' || n, 'Pace ' || n, " . TestClock::SQL_NOW . ", false FROM generate_series(1, 20) AS n",
        );
        $this->database->executeStatement(
            "INSERT INTO player_baseline (id, player_id, pieces_count, baseline_seconds, qualifying_solves_count, computed_at, baseline_type)
            SELECT CAST('019b0c01-0000-7000-8000-' || lpad(n::text, 12, '0') AS uuid), CAST('019b0c00-0000-7000-8000-' || lpad(n::text, 12, '0') AS uuid), 500, 2950 + 50 * n, 5, " . TestClock::SQL_NOW . ", 'direct'
            FROM generate_series(1, 20) AS n",
        );
        /** @var list<int|string> $paces */
        $paces = $this->database->fetchFirstColumn("SELECT baseline_seconds FROM player_baseline WHERE pieces_count = 500 AND baseline_type = 'direct' AND baseline_seconds > 0 ORDER BY baseline_seconds");
        $median = self::median(array_map(intval(...), $paces));

        $estimates = $this->estimates->forPuzzles($newcomer, [PuzzleFixture::PUZZLE_500_01, PuzzleFixture::PUZZLE_9000], static fn (): bool => true);

        $estimate = $estimates[PuzzleFixture::PUZZLE_500_01] ?? null;
        self::assertNotNull($estimate);
        self::assertSame(EstimateBasis::Community, $estimate->basis);
        self::assertTrue($estimate->isCommunity());
        self::assertNull($estimate->prediction, 'not personal: no window, no bar');
        self::assertNull($estimate->window());
        self::assertNull($estimate->bar());
        self::assertSame((int) round($median), $estimate->communitySeconds);
        self::assertSame(count($paces), $estimate->communityPlayers);
        // Too few puzzlers at 9000 pieces: nothing
        self::assertArrayNotHasKey(PuzzleFixture::PUZZLE_9000, $estimates);

        // A player with a pace never gets the community time - an estimate of their own or nothing
        foreach ($this->estimates->forPuzzles(PlayerFixture::PLAYER_WITH_STRIPE, $this->allPuzzleIds(), static fn (): bool => true) as $own) {
            self::assertNotSame(EstimateBasis::Community, $own->basis);
        }
    }

    public function testTheCollectionContextRanksAmongTheOwnCollectionsPuzzlesOfThatSize(): void
    {
        /** @var list<array{puzzle_id: string, difficulty_score: float}> $rated */
        $rated = $this->database->fetchAllAssociative(
            'SELECT pd.puzzle_id, pd.difficulty_score FROM puzzle_difficulty pd JOIN puzzle p ON p.id = pd.puzzle_id WHERE p.pieces_count = 500 AND pd.difficulty_score IS NOT NULL AND pd.sample_size >= :rated ORDER BY pd.difficulty_score DESC',
            ['rated' => TimePredictionCalculator::RATED_INDICES],
        );
        self::assertNotSame([], $rated);

        $rank = $this->estimates->collectionRank(PlayerFixture::PLAYER_WITH_STRIPE, PuzzleFixture::PUZZLE_500_04, 500, 5.0);

        // Harder than everything: nothing harder; the puzzle is not in the collection - no context line
        self::assertSame(0, $rank->harder);
        self::assertNull($rank->context());
    }

    /**
     * @param list<int> $values
     */
    private static function median(array $values): float
    {
        sort($values);
        $count = count($values);
        $middle = intdiv($count, 2);

        return $count % 2 === 1 ? (float) $values[$middle] : ($values[$middle - 1] + $values[$middle]) / 2;
    }

    private function storeEstimate(string $puzzleId, float $logDifficulty, string $artworkKind): void
    {
        $image = 'box-' . $puzzleId . '.jpg';
        $this->database->executeStatement('UPDATE puzzle SET image = :image WHERE id = :id', ['image' => $image, 'id' => $puzzleId]);

        /** @var MessageBusInterface $messageBus */
        $messageBus = self::getContainer()->get(MessageBusInterface::class);
        $messageBus->dispatch(new RecordPuzzleDifficultyEstimates([
            new PuzzleDifficultyEstimateInput(
                puzzleId: $puzzleId,
                model: 'ridge-siglip2-dinov2',
                modelVersion: '1.0.0',
                logDifficulty: $logDifficulty,
                logP10: $logDifficulty - 0.2,
                logP90: $logDifficulty + 0.25,
                outOfFold: true,
                trainingSize: 4979,
                imageFingerprint: PuzzleImageFingerprint::of($image),
                computedAt: null,
            ),
        ]));

        $this->database->executeStatement(
            "INSERT INTO puzzle_image_analysis (id, puzzle_id, analyzer, analyzer_version, image_fingerprint, result, analyzed_at, received_at)
            VALUES (:id, :puzzle, 'artwork', '1.0.0', :fingerprint, CAST(:result AS jsonb), NULL, " . TestClock::SQL_NOW . ")",
            [
                'id' => Uuid::uuid7()->toString(),
                'puzzle' => $puzzleId,
                'fingerprint' => PuzzleImageFingerprint::of($image),
                'result' => json_encode(['kind' => $artworkKind], JSON_THROW_ON_ERROR),
            ],
        );
    }

    /**
     * @return list<string>
     */
    private function allPuzzleIds(): array
    {
        /** @var list<string> $ids */
        $ids = $this->database->fetchFirstColumn('SELECT id FROM puzzle ORDER BY id');

        return $ids;
    }
}
