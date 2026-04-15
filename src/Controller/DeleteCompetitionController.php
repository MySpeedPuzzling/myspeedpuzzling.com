<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\DeleteCompetition;
use SpeedPuzzling\Web\Security\CompetitionDeleteVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class DeleteCompetitionController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/smazat-udalost/{competitionId}',
            'en' => '/en/delete-event/{competitionId}',
            'es' => '/es/delete-event/{competitionId}',
            'ja' => '/ja/delete-event/{competitionId}',
            'fr' => '/fr/delete-event/{competitionId}',
            'de' => '/de/delete-event/{competitionId}',
        ],
        name: 'delete_competition',
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionDeleteVoter::COMPETITION_DELETE, $competitionId);

        $token = (string) $request->request->get('_token');

        if (!$this->isCsrfTokenValid('delete_competition_' . $competitionId, $token)) {
            throw $this->createAccessDeniedException();
        }

        $this->messageBus->dispatch(new DeleteCompetition(competitionId: $competitionId));

        $this->addFlash('success', $this->translator->trans('competition.flash.deleted'));

        return $this->redirectToRoute('events');
    }
}
