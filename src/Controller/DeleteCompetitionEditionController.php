<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\CompetitionNotFound;
use SpeedPuzzling\Web\Message\DeleteCompetition;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Security\CompetitionDeleteVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class DeleteCompetitionEditionController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionRepository $competitionRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/smazat-rocnik/{competitionId}',
            'en' => '/en/delete-edition/{competitionId}',
            'es' => '/es/delete-edition/{competitionId}',
            'ja' => '/ja/delete-edition/{competitionId}',
            'fr' => '/fr/delete-edition/{competitionId}',
            'de' => '/de/delete-edition/{competitionId}',
        ],
        name: 'delete_competition_edition',
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionDeleteVoter::COMPETITION_DELETE, $competitionId);

        $token = (string) $request->request->get('_token');

        if (!$this->isCsrfTokenValid('delete_competition_' . $competitionId, $token)) {
            throw $this->createAccessDeniedException();
        }

        $edition = $this->competitionRepository->get($competitionId);
        $series = $edition->series;

        if ($series === null) {
            throw new CompetitionNotFound();
        }

        $seriesId = $series->id->toString();

        $this->messageBus->dispatch(new DeleteCompetition(competitionId: $competitionId));

        $this->addFlash('success', $this->translator->trans('edition.flash.deleted'));

        return $this->redirectToRoute('manage_competition_series', ['seriesId' => $seriesId]);
    }
}
