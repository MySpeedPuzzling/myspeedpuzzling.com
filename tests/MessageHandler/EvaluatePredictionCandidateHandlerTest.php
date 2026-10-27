<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\MessageHandler;

use Doctrine\ORM\EntityManagerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\ImageEstimateEvaluation;
use SpeedPuzzling\Web\Message\AddPuzzleSolvingTime;
use SpeedPuzzling\Web\Message\EvaluatePredictionCandidate;
use SpeedPuzzling\Web\Query\GetLatestImageEstimateEvaluation;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\Evaluation\PredictionCandidateGate;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\ImageEstimates\ImageEstimateEvaluator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\PuzzleIntelligenceRecalculator;
use SpeedPuzzling\Web\Services\PuzzleIntelligence\TimePredictionCalculator;
use SpeedPuzzling\Web\Tests\DataFixtures\PlayerFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\PuzzleFixture;
use SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence\Evaluation\RecordPath;
use SpeedPuzzling\Web\Tests\Services\PuzzleIntelligence\FitsThePredictionModel;
use SpeedPuzzling\Web\Value\PiecesGroup;
use SpeedPuzzling\Web\Value\PredictorKind;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;

/**
 * Gate v2 is generic over the predictor and model version (EvaluatePredictionCandidate): any stored side-by-side
 * prediction is gated against a reference the same way, its record kept under its own subject - here the shown
 * statistical model judged against itself, the only rows the fixtures have. The rules: PredictionCandidateEvaluatorTest.
 */
final class EvaluatePredictionCandidateHandlerTest extends KernelTestCase
{
    use FitsThePredictionModel;

    private MessageBusInterface $messageBus;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = self::getContainer();
        $this->messageBus = $container->get(MessageBusInterface::class);

        /** @var PuzzleIntelligenceRecalculator $recalculator */
        $recalculator = $container->get(PuzzleIntelligenceRecalculator::class);
        $recalculator->recalculate();
        $this->fitPredictionModel();

        // A live statistical row of the current model
        $this->messageBus->dispatch(new AddPuzzleSolvingTime(
            timeId: Uuid::uuid7(),
            userId: PlayerFixture::PLAYER_REGULAR_USER_ID,
            puzzleId: PuzzleFixture::PUZZLE_500_05,
            competitionId: null,
            time: '00:45:00',
            comment: null,
            finishedPuzzlesPhoto: null,
            groupPlayers: [],
            finishedAt: null,
            firstAttempt: false,
            unboxed: false,
        ));
    }

    public function testStoresTheCandidatesRecordUnderItsOwnSubject(): void
    {
        $version = TimePredictionCalculator::MODEL_VERSION;
        $evaluation = $this->evaluate(new EvaluatePredictionCandidate(PredictorKind::Statistical, $version, PredictorKind::Statistical, $version));
        $subject = sprintf('statistical@%d vs statistical@%d', $version, $version);

        self::assertSame($subject, $evaluation->subject);
        self::assertSame(ImageEstimateEvaluator::GATE_VERSION, $evaluation->gateVersion);
        self::assertSame($version, $evaluation->modelVersion);
        self::assertNull($evaluation->newerThan, 'Every puzzle');

        $groups = array_column($evaluation->groups, null, 'sizeGroup');
        self::assertArrayHasKey(PiecesGroup::FiveHundred->value, $groups);
        self::assertSame('any', $groups[PiecesGroup::FiveHundred->value]['imageKind']);
        self::assertGreaterThanOrEqual(1, $evaluation->overall['rows']);
        self::assertSame(['live'], array_keys(RecordPath::array($groups, PiecesGroup::FiveHundred->value, 'slices')));

        // Against itself: no error difference at all
        foreach (RecordPath::array($groups, PiecesGroup::FiveHundred->value, 'skill') as $bucket) {
            self::assertSame(0.0, RecordPath::at($bucket, 'meanDifference'));
        }

        /** @var GetLatestImageEstimateEvaluation $latest */
        $latest = self::getContainer()->get(GetLatestImageEstimateEvaluation::class);
        self::assertSame($evaluation->id->toString(), $latest->latest($subject)?->id);
        self::assertNull($latest->latest(), 'The picture estimates\' record is another subject');

        // A handful of solves never passes: closed, saying why
        /** @var PredictionCandidateGate $gate */
        $gate = self::getContainer()->get(PredictionCandidateGate::class);
        self::assertNotSame([], $gate->failuresFor(PredictorKind::Statistical, $version, PredictorKind::Statistical, $version, PiecesGroup::FiveHundred));
        self::assertStringContainsString('no gate record', $gate->failuresFor(PredictorKind::Blend, $version + 1, PredictorKind::Statistical, $version, PiecesGroup::FiveHundred)[0]);
        self::assertSame(['no evaluated predictions in this group'], $gate->failuresFor(PredictorKind::Statistical, $version, PredictorKind::Statistical, $version, PiecesGroup::From1500));
    }

    public function testACandidateWithoutRowsStoresAnEmptyRecord(): void
    {
        $evaluation = $this->evaluate(new EvaluatePredictionCandidate(PredictorKind::Blend, TimePredictionCalculator::MODEL_VERSION + 1, PredictorKind::Statistical, TimePredictionCalculator::MODEL_VERSION, newerThanMonths: 6));

        self::assertSame([], $evaluation->groups);
        self::assertSame(0, $evaluation->overall['rows']);
        self::assertNull($evaluation->splitAt);
        self::assertNotNull($evaluation->newerThan);
    }

    private function evaluate(EvaluatePredictionCandidate $message): ImageEstimateEvaluation
    {
        $evaluation = $this->messageBus->dispatch($message)->last(HandledStamp::class)?->getResult();
        self::assertInstanceOf(ImageEstimateEvaluation::class, $evaluation);
        self::getContainer()->get(EntityManagerInterface::class)->clear();

        return $evaluation;
    }
}
