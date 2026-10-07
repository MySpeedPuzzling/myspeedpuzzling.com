<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OfficialResults;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetRoundResultEntries;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\Export\RoundResultsExporter;
use SpeedPuzzling\Web\Services\OfficialResultsRounds;
use SpeedPuzzling\Web\Value\ExportFormat;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\ResponseHeaderBag;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Component\String\Slugger\AsciiSlugger;

/**
 * The results desk's "Export": one round's official results as CSV or XLSX (RoundResultsExporter) - the event's
 * organisers only, never stored by a browser or a proxy (names of people).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ExportRoundResultsController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly GetRoundResultEntries $getRoundResultEntries,
        private readonly OfficialResultsRounds $officialResultsRounds,
        private readonly RoundResultsExporter $exporter,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/sprava-vysledku-kola/{roundId}/export/{format}',
            'en' => '/en/manage-round-results/{roundId}/export/{format}',
            'es' => '/es/manage-round-results/{roundId}/export/{format}',
            'ja' => '/ja/manage-round-results/{roundId}/export/{format}',
            'fr' => '/fr/manage-round-results/{roundId}/export/{format}',
            'de' => '/de/manage-round-results/{roundId}/export/{format}',
        ],
        name: 'results_desk_export',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT, 'format' => 'csv|xlsx'],
        methods: ['GET'],
    )]
    public function __invoke(string $roundId, string $format): Response
    {
        $round = $this->roundRepository->get($roundId);
        $competitionId = $round->competition->id->toString();
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $roundId = $round->id->toString();
        $timezone = 'UTC';
        $piecesCount = null;

        foreach ($this->officialResultsRounds->forCompetition($competitionId) as $candidate) {
            if ($candidate->id() === $roundId) {
                $timezone = $candidate->timezone;
                $piecesCount = $candidate->overview->piecesCount;
            }
        }

        $file = $this->exporter->export(
            $this->getRoundResultEntries->forRound($roundId),
            $piecesCount,
            $timezone,
            ExportFormat::from($format),
        );

        $slugger = new AsciiSlugger();
        $filename = sprintf(
            '%s-%s-results.%s',
            $slugger->slug($round->competition->name)->lower()->truncate(60)->toString() ?: 'event',
            $slugger->slug($round->name)->lower()->truncate(40)->toString() ?: 'round',
            $file->fileExtension,
        );

        return new Response($file->content, Response::HTTP_OK, [
            'Content-Type' => $file->contentType,
            'Content-Disposition' => new ResponseHeaderBag()->makeDisposition(ResponseHeaderBag::DISPOSITION_ATTACHMENT, $filename),
            'Cache-Control' => 'private, no-store',
            'X-Robots-Tag' => 'noindex',
        ]);
    }
}
