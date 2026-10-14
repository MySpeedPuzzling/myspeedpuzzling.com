<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OnlineRounds;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotFound;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Results\OnlineAccuracyRow;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Security\OnlineRoundsVoter;
use SpeedPuzzling\Web\Services\Export\SpreadsheetSafeValue;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineAccuracy;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * The accuracy report of an online round (docs/features/online-competitions/README.md §5.9, contract §5
 * `online_accuracy`, OnlineAccuracy): per participant the tap, the confirmed moment, the reviewer's adjustment, its
 * source and how late the tap arrived, with the adjustment's distribution; Phase 2 adds the detection of raised hands
 * (the detected moment, the detection error, the delta, missed and false raises, the camera view, the phone) and a
 * breakdown by phone and by view - as a page, or with `?format=csv` as CSV (CSV_COLUMNS - stable names, for the bench
 * and spreadsheets; every cell through SpreadsheetSafeValue: names are typed by people, User-Agents by phones).
 * Organisers of the event for whom online rounds are on (COMPETITION_EDIT + ONLINE_ROUNDS_MANAGE).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class OnlineAccuracyController extends AbstractController
{
    // Phase 2 inserted detected_moment_ms, detection_error_ms and delta_ms after adjustment_ms (OnlineAccuracy::MEASURES)
    // and appended the rest - a column is never renamed or moved again, new ones go to the end
    public const array CSV_COLUMNS = [
        'participant',
        'status',
        'tap_moment_ms',
        'tap_elapsed_ms',
        'confirmed_moment_ms',
        'confirmed_elapsed_ms',
        'adjustment_ms',
        'detected_moment_ms',
        'detection_error_ms',
        'delta_ms',
        'source',
        'arrived_late_ms',
        'official_seconds',
        'official_pieces_placed',
        'missed_raises',
        'false_raises',
        'view',
        'device',
        'browser',
        // Stream P2F: finishes on a phone whose detector did not watch - never counted in missed_raises
        'detector_not_running',
    ];

    public function __construct(
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly OnlineRoundRepository $onlineRoundRepository,
        private readonly OnlineAccuracy $onlineAccuracy,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/online-kola/{roundId}/presnost',
            'en' => '/en/online-rounds/{roundId}/accuracy',
            'es' => '/es/online-rounds/{roundId}/accuracy',
            'ja' => '/ja/online-rounds/{roundId}/accuracy',
            'fr' => '/fr/online-rounds/{roundId}/accuracy',
            'de' => '/de/online-rounds/{roundId}/accuracy',
        ],
        name: 'online_accuracy',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $roundId): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $competitionId = $round->competition->id->toString();
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);
        $this->denyAccessUnlessGranted(OnlineRoundsVoter::ONLINE_ROUNDS_MANAGE, $competitionId);

        $onlineRound = $this->onlineRoundRepository->ofRound($round->id->toString()) ?? throw new OnlineRoundNotFound();
        $report = $this->onlineAccuracy->forRound($round, $onlineRound);

        if ($request->query->getString('format') === 'csv') {
            $slug = new AsciiSlugger()->slug($round->competition->name . ' ' . $round->name)->lower()->truncate(80)->toString();

            return new Response(self::csv($report['rows']), Response::HTTP_OK, [
                'Content-Type' => 'text/csv; charset=UTF-8',
                'Content-Disposition' => new ResponseHeaderBag()->makeDisposition(
                    ResponseHeaderBag::DISPOSITION_ATTACHMENT,
                    ($slug !== '' ? $slug : 'round') . '-accuracy.csv',
                ),
                'Cache-Control' => 'private, no-store',
                'X-Robots-Tag' => 'noindex, nofollow',
            ]);
        }

        $response = $this->render('online_rounds/accuracy.html.twig', [
            'competition' => $round->competition,
            'round' => $round,
            'online_round' => $onlineRound,
            'rows' => $report['rows'],
            'summaries' => $report['summaries'],
            'started_at_ms' => $report['startedAtMs'],
            'detection' => $report['detection'],
            'detection_shown' => $report['detectionShown'],
            'by_device' => $report['byDevice'],
            'by_view' => $report['byView'],
            'detector_not_running' => $report['detectorNotRunning'],
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    /**
     * @param list<OnlineAccuracyRow> $rows
     */
    private static function csv(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        assert($handle !== false);

        // A BOM, so a spreadsheet opened by double click reads names with accents as UTF-8
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, self::CSV_COLUMNS, ',', '"', '');

        foreach ($rows as $row) {
            fputcsv($handle, array_map(SpreadsheetSafeValue::forCsv(...), [
                $row->name,
                $row->status,
                $row->tapMomentMs,
                $row->tapElapsedMs,
                $row->confirmedMomentMs,
                $row->confirmedElapsedMs,
                $row->adjustmentMs,
                $row->detectedMomentMs,
                $row->detectionErrorMs,
                $row->deltaMs,
                $row->source,
                $row->arrivedLateMs,
                $row->official->seconds,
                $row->official->piecesPlaced,
                $row->missedRaises,
                $row->falseRaises,
                $row->view,
                $row->device,
                $row->browser,
                $row->detectorNotRunning,
            ]), ',', '"', '');
        }

        rewind($handle);
        $content = stream_get_contents($handle);
        fclose($handle);

        return $content !== false ? $content : '';
    }
}
