<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\DeleteCompetitionRound;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class DeleteCompetitionRoundController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/smazat-kolo-udalosti/{roundId}',
            'en' => '/en/delete-event-round/{roundId}',
        ],
        name: 'delete_competition_round',
        methods: ['POST'],
    )]
    public function __invoke(string $roundId): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $competitionId = $round->competition->id->toString();
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $this->messageBus->dispatch(new DeleteCompetitionRound(roundId: $roundId));

        $this->addFlash('success', $this->translator->trans('competition.flash.round_deleted'));

        return $this->redirectToRoute('manage_competition_rounds', ['competitionId' => $competitionId]);
    }
}
