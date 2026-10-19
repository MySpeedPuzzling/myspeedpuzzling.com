<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\DBAL\Connection;
use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ImageEstimateEvaluation;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\EvaluateImageEstimates;
use SpeedPuzzling\Web\Message\RecordPuzzleDifficultyEstimates;
use SpeedPuzzling\Web\Query\GetLatestImageEstimateEvaluation;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\ImageEstimates\ImageEstimateGate;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence\FitsThePredictionModel;
use SpeedPuzzling\Web\Value\ImageKind;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\PuzzleDifficultyEstimateInput;
use SpeedPuzzling\Web\Value\PuzzleImageFingerprint;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * The gate record of the picture-based estimates (EvaluateImageEstimates) from stored side-by-side rows, and the gate
 * reading it (ImageEstimateGate) - failing closed. The numbers themselves: ImageEstimateEvaluatorTest.
 */
final class EvaluateImageEstimatesHandlerTest extends KernelTestCase
{
    use FitsThePredictionModel;

    /** The estimates this test pushes: "<model> <model version>" (ImagePriorInput::generation()) */
    private const string GENERATION = 'ridge-siglip2-full 1.0.0';

    private Connection $database;
    private MessageBusInterface $messageBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();

        $this->database = $container->get(Connection::class);
        $this->messageBus = $container->get(MessageBusInterface::class);

        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = $container->get(PuzzleIntelligenceRecalculator::class);
        $recalculator->recalculate();
        $this->fitPredictionModel();

        foreach ([PuzzleFixture::PUZZLE_500_05 => 0.3, PuzzleFixture::PUZZLE_500_04 => 0.2] as $puzzleId => $log) {
            $image = 'box-' . $puzzleId . '.jpg';
            $this->database->executeStatement('UPDATE puzzle SET image = :image WHERE id = :id', ['image' => $image, 'id' => $puzzleId]);
            $this->messageBus->dispatch(new RecordPuzzleDifficultyEstimates([
                new PuzzleDifficultyEstimateInput($puzzleId, 'ridge-siglip2-full', '1.0.0', $log, $log - 0.2, $log + 0.25, true, 4979, PuzzleImageFingerprint::of($image), null),
            ]));
        }

        // Two first tries of PLAYER_REGULAR with image rows: PUZZLE_500_05 (2 indices), PUZZLE_500_04 (none)
        $this->addSoloTime(PuzzleFixture::PUZZLE_500_05, '00:45:00');
        $this->addSoloTime(PuzzleFixture::PUZZLE_500_04, '00:50:00');
    }

    public function testStoresTheGateRecordPerSizeGroupAndImageKind(): void
    {
        $evaluation = $this->evaluate();

        self::assertSame(TimePredictionCalculator::MODEL_VERSION, $evaluation->modelVersion);
        self::assertSame(10, $evaluation->measuredMinIndices);
        self::assertSame($evaluation->id->toString(), self::getContainer()->get(GetLatestImageEstimateEvaluation::class)->latest()?->id);

        self::assertSame(2, $evaluation->overall['rows']);
        self::assertSame(2, $evaluation->overall['puzzles']);
        self::assertSame(2, $evaluation->overall['pairedRows'], 'Statistical and blend predicted both');
        self::assertSame(['0', '1-2'], array_column($evaluation->byK, 'k'));

        // No artwork analysis: the kind of its own, never pooled with the others
        self::assertCount(1, $evaluation->groups);
        self::assertSame(PiecesGroup::FiveHundred->value, $evaluation->groups[0]['sizeGroup']);
        self::assertSame(ImageKind::Unknown->value, $evaluation->groups[0]['imageKind']);
        self::assertSame(0, $evaluation->groups[0]['measuredPuzzles'], 'The fixtures measure no puzzle from 10 indices on');
    }

    /**
     * Only newer puzzles, never a suspicious time, never a secret puzzle, never a live row that had no estimate.
     */
    public function testLeavesOutWhatTheGatesDoNotMeasure(): void
    {
        $this->database->executeStatement("UPDATE puzzle SET added_at = NOW() - INTERVAL '2 years' WHERE id = :id", ['id' => PuzzleFixture::PUZZLE_500_04]);
        self::assertSame(1, $this->evaluate()->overall['rows'], 'An older puzzle is out');
        self::assertSame(2, $this->evaluate(newerThanMonths: 36)->overall['rows']);

        $this->database->executeStatement("UPDATE puzzle_solving_time SET suspicious = true WHERE puzzle_id = :id AND player_id = :player", ['id' => PuzzleFixture::PUZZLE_500_05, 'player' => PlayerFixture::PLAYER_REGULAR]);
        self::assertSame(1, $this->evaluate(newerThanMonths: 36)->overall['rows'], 'A suspicious time is out');

        $this->database->executeStatement("UPDATE puzzle SET hide_image_until = NOW() + INTERVAL '30 days' WHERE id = :id", ['id' => PuzzleFixture::PUZZLE_500_04]);
        self::assertSame(0, $this->evaluate(newerThanMonths: 36)->overall['rows'], 'A secret puzzle is out, whatever rows it has');
        self::assertSame([], $this->evaluate(newerThanMonths: 36)->groups);
    }

    /**
     * The gate fails closed: before any record, for a group that is not in it, for too few puzzles, for a record of
     * another model version, for an estimate generation the record did not measure - and opens for a group whose
     * stored numbers pass.
     */
    public function testTheGateReadsTheLatestRecordAndFailsClosed(): void
    {
        $gate = $this->gate();
        self::assertFalse($gate->passesFor(PiecesGroup::FiveHundred, ImageKind::Unknown, self::GENERATION), 'No record yet');
        self::assertStringContainsString('no gate record', $gate->failuresFor(PiecesGroup::FiveHundred, ImageKind::Unknown, self::GENERATION)[0]);

        $this->evaluate();
        $gate->reset();
        self::assertFalse($gate->passesFor(PiecesGroup::FiveHundred, ImageKind::Unknown, self::GENERATION), 'Too few measured puzzles');
        self::assertFalse($gate->passesFor(PiecesGroup::FiveHundred, ImageKind::Flat, self::GENERATION), 'Not in the record');

        $passing = [
            'sizeGroup' => PiecesGroup::FiveHundred->value,
            'imageKind' => ImageKind::Flat->value,
            'measuredPuzzles' => 140,
            'spearman' => 0.71,
            'tierExact' => 0.6,
            'tierWithinOne' => 0.9,
            'alwaysAverage' => 0.46,
            'window50Hit' => 0.5,
            'window80Hit' => 0.8,
            'estimateModels' => [self::GENERATION => 140],
        ];
        $this->storeRecord(TimePredictionCalculator::MODEL_VERSION, [$passing]);
        $gate->reset();
        self::assertTrue($gate->passesFor(PiecesGroup::FiveHundred, ImageKind::Flat, self::GENERATION));
        // A record vouches only for the estimate generation it measured: a derive model change stays closed until a
        // new record measured its estimates
        self::assertFalse($gate->passesFor(PiecesGroup::FiveHundred, ImageKind::Flat, 'ridge-siglip2-full 2.0.0'), 'Another estimate generation');
        self::assertStringContainsString('did not measure', $gate->failuresFor(PiecesGroup::FiveHundred, ImageKind::Flat, 'ridge-siglip2-full 2.0.0')[0]);
        self::assertFalse($gate->passesFor(PiecesGroup::Thousand, ImageKind::Flat, self::GENERATION), 'Per size group');
        self::assertFalse($gate->passesFor(PiecesGroup::FiveHundred, ImageKind::Unknown, self::GENERATION), 'Per image kind');

        $this->storeRecord(TimePredictionCalculator::MODEL_VERSION + 1, [$passing]);
        $gate->reset();
        self::assertFalse($gate->passesFor(PiecesGroup::FiveHundred, ImageKind::Flat, self::GENERATION), 'A record of another model version');
    }

    private function evaluate(int $newerThanMonths = 12): ImageEstimateEvaluation
    {
        $evaluation = $this->messageBus->dispatch(new EvaluateImageEstimates($newerThanMonths))->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(ImageEstimateEvaluation::class, $evaluation);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return $evaluation;
    }

    private function gate(): ImageEstimateGate
    {
        return self::getContainer()->get(ImageEstimateGate::class);
    }

    /**
     * @param list<array<string, mixed>> $groups
     */
    private function storeRecord(int $modelVersion, array $groups): void
    {
        // A second later than anything stored before: the latest record
        $this->database->executeStatement(
            "INSERT INTO image_estimate_evaluation (id, evaluated_at, model_version, newer_than, measured_min_indices, groups, overall, by_k)
            VALUES (:id, (SELECT COALESCE(MAX(evaluated_at), NOW()) FROM image_estimate_evaluation) + INTERVAL '1 second', :version, NOW() - INTERVAL '1 year', 10, CAST(:groups AS jsonb), '{}', '[]')",
            ['id' => Uuid::uuid7()->toString(), 'version' => $modelVersion, 'groups' => json_encode($groups, JSON_THROW_ON_ERROR)],
        );
    }

    private function addSoloTime(string $puzzleId, string $time): void
    {
        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: Uuid::uuid7(),
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleId: $puzzleId,
            competitionId: null,
            time: $time,
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));
    }
}
