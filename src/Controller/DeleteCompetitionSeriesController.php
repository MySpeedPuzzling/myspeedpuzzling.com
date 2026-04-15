<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\DeleteCompetitionSeries;
use SpeedPuzzling\Web\Security\CompetitionSeriesDeleteVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class DeleteCompetitionSeriesController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/smazat-serii/{seriesId}',
            'en' => '/en/delete-series/{seriesId}',
            'es' => '/es/delete-series/{seriesId}',
            'ja' => '/ja/delete-series/{seriesId}',
            'fr' => '/fr/delete-series/{seriesId}',
            'de' => '/de/delete-series/{seriesId}',
        ],
        name: 'delete_competition_series',
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $seriesId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionSeriesDeleteVoter::COMPETITION_SERIES_DELETE, $seriesId);

        $token = (string) $request->request->get('_token');

        if (!$this->isCsrfTokenValid('delete_competition_series_' . $seriesId, $token)) {
            throw $this->createAccessDeniedException();
        }

        $this->messageBus->dispatch(new DeleteCompetitionSeries(seriesId: $seriesId));

        $this->addFlash('success', $this->translator->trans('series.flash.deleted'));

        return $this->redirectToRoute('events');
    }
}
