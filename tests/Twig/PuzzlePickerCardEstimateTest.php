<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Twig;

use PHPUnit\Framework\Attributes\DataProvider;
use SpeedPuzzling\Web\Results\PersonalEstimate;
use SpeedPuzzling\Web\Results\PersonalEstimates;
use SpeedPuzzling\Web\Results\PuzzlePickerSuggestion;
use SpeedPuzzling\Web\Results\TimePredictionResult;
use SpeedPuzzling\Web\Value\DifficultyTier;
use SpeedPuzzling\Web\Value\EstimateBasis;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\DomCrawler\Crawler;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\RequestStack;
use Twig\Environment;

/**
 * The Picker card's "vs your best" line under a personal estimate (docs/features/insights/estimates.md): measured from
 * the raw prediction, never the rounded time, and said only when the difference is at least the shown time's rounding
 * step - "≈ 3h" must never "beat" a 3:04:00 best by 30 seconds.
 */
final class PuzzlePickerCardEstimateTest extends KernelTestCase
{
    private const string PUZZLE = '018d0003-0000-0000-0000-000000000001';

    /**
     * @return iterable<string, array{int, int, null|string}>
     */
    public static function cases(): iterable
    {
        // best, raw prediction => the line's class (null = no line)
        yield '30 s slower than a 3:04:00 best, shown as ≈ 3h: nothing' => [11040, 11070, null];
        yield '30 s faster than a 3:04:00 best: nothing either' => [11040, 11010, null];
        yield '9 min under an hour-plus best: under the 10-minute step, nothing' => [11040, 10500, null];
        yield '15 min faster than the best: beats it' => [11040, 10140, 'text-success'];
        yield '12 min slower than the best: slower' => [3000, 3720, 'text-muted'];
        yield '4 min faster on a 50-minute puzzle: under the 5-minute step, nothing' => [3000, 2760, null];
    }

    #[DataProvider('cases')]
    public function testTheBestTimeLineComesFromTheRawPrediction(int $best, int $predicted, null|string $line): void
    {
        self::bootKernel();
        $container = self::getContainer();
        /** @var RequestStack $requests */
        $requests = $container->get(RequestStack::class);
        $requests->push(Request::create('/en/what-to-solve-next?seed=6c6c6c6c'));
        /** @var Environment $twig */
        $twig = $container->get(Environment::class);

        $estimate = new PersonalEstimate(
            self::PUZZLE,
            500,
            EstimateBasis::Measured,
            new TimePredictionResult($predicted, (int) round($predicted * 0.95), (int) round($predicted * 1.06), 1.0, range80LowSeconds: (int) round($predicted * 0.88), range80HighSeconds: (int) round($predicted * 1.13)),
            12,
            DifficultyTier::Average,
            false,
        );

        $html = $twig->render('puzzle_picker/_card.html.twig', [
            'suggestion' => self::suggestion($best),
            'position' => 2,
            'criteria' => ['isAuthenticated' => true, 'insightsAllowed' => true, 'predictionsAllowed' => true],
            'predictions' => [self::PUZZLE => $estimate->prediction],
            'estimates' => PersonalEstimates::shown([self::PUZZLE => $estimate]),
        ]);
        $card = new Crawler($html);

        self::assertCount(1, $card->filter('[data-testid="estimate-line"]'));
        $vsBest = $card->filter('.puzzle-picker-prediction-vs-best');

        if ($line === null) {
            self::assertCount(0, $vsBest, 'a difference under the rounding step says nothing');

            return;
        }

        self::assertCount(1, $vsBest);
        self::assertStringContainsString($line, (string) $vsBest->attr('class'));
    }

    private static function suggestion(int $best): PuzzlePickerSuggestion
    {
        return new PuzzlePickerSuggestion(
            puzzleId: self::PUZZLE,
            puzzleName: 'Picker card',
            puzzleIdentificationNumber: null,
            puzzleEan: null,
            manufacturerId: null,
            manufacturerName: 'Brand',
            piecesCount: 500,
            puzzleImage: null,
            puzzleImageRatio: null,
            communitySolvedCountSolo: 20,
            communityAverageTimeSolo: 3600,
            mySolveCountAny: 1,
            mySolveCountSolo: 1,
            myFastestSeconds: $best,
            myFirstSeconds: $best,
            myLatestSeconds: $best,
            myLastSolvedAt: null,
            myFastestPairSeconds: null,
            myFastestTeamSeconds: null,
            myRelaxSolveCount: 0,
            inMyCollection: true,
            isBorrowed: false,
            isLentOut: false,
        );
    }
}
