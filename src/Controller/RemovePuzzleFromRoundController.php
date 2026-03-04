<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\RemovePuzzleFromCompetitionRound;
use SpeedPuzzling\Web\Repository\CompetitionRoundPuzzleRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_FULLY')]
final class RemovePuzzleFromRoundController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionRoundPuzzleRepository $competitionRoundPuzzleRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/odebrat-puzzle-z-kola/{roundPuzzleId}',
            'en' => '/en/remove-puzzle-from-round/{roundPuzzleId}',
        ],
        name: 'remove_puzzle_from_round',
        methods: ['POST'],
    )]
    public function __invoke(string $roundPuzzleId): Response
    {
        $roundPuzzle = $this->competitionRoundPuzzleRepository->get($roundPuzzleId);
        $round = $roundPuzzle->round;
        $competitionId = $round->competition->id->toString();
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $this->messageBus->dispatch(new RemovePuzzleFromCompetitionRound(roundPuzzleId: $roundPuzzleId));

        $this->addFlash('success', $this->translator->trans('competition.flash.puzzle_removed'));

        return $this->redirectToRoute('manage_round_puzzles', ['roundId' => $round->id->toString()]);
    }
}
