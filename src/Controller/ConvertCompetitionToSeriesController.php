<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Message\ConvertCompetitionToSeries;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class ConvertCompetitionToSeriesController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/prevest-na-serii/{competitionId}',
            'en' => '/en/convert-to-series/{competitionId}',
            'es' => '/es/convert-to-series/{competitionId}',
            'ja' => '/ja/convert-to-series/{competitionId}',
            'fr' => '/fr/convert-to-series/{competitionId}',
            'de' => '/de/convert-to-series/{competitionId}',
        ],
        name: 'convert_competition_to_series',
        methods: ['POST'],
    )]
    public function __invoke(string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $seriesId = Uuid::uuid7();

        $this->messageBus->dispatch(new ConvertCompetitionToSeries(
            competitionId: $competitionId,
            seriesId: $seriesId,
        ));

        $this->addFlash('success', $this->translator->trans('competition.flash.converted_to_series'));

        return $this->redirectToRoute('manage_competition_series', ['seriesId' => $seriesId->toString()]);
    }
}
