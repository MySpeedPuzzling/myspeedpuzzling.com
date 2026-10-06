<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\RevealRoundPuzzleNow;
use SpeedPuzzling\Web\Repository\CompetitionRoundPuzzleRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class RevealRoundPuzzleNowController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionRoundPuzzleRepository $competitionRoundPuzzleRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/odhalit-puzzle-kola/{roundPuzzleId}',
            'en' => '/en/reveal-round-puzzle/{roundPuzzleId}',
            'es' => '/es/reveal-round-puzzle/{roundPuzzleId}',
            'ja' => '/ja/reveal-round-puzzle/{roundPuzzleId}',
            'fr' => '/fr/reveal-round-puzzle/{roundPuzzleId}',
            'de' => '/de/reveal-round-puzzle/{roundPuzzleId}',
        ],
        name: 'reveal_round_puzzle_now',
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $roundPuzzleId): Response
    {
        $roundPuzzle = $this->competitionRoundPuzzleRepository->get($roundPuzzleId);
        $round = $roundPuzzle->round;
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $round->competition->id->toString());

        if ($this->isCsrfTokenValid('reveal_round_puzzle_' . $roundPuzzleId, (string) $request->request->get('_token'))) {
            $this->messageBus->dispatch(new RevealRoundPuzzleNow(roundPuzzleId: $roundPuzzleId));

            $this->addFlash('success', $this->translator->trans('competition.reveal.flash.revealed', [
                '%puzzle%' => $roundPuzzle->puzzle->name,
            ]));
        } else {
            $this->addFlash('danger', $this->translator->trans('competition.reveal.flash.invalid'));
        }

        return $this->redirectToRoute('manage_round_puzzles', ['roundId' => $round->id->toString()], Response::HTTP_SEE_OTHER);
    }
}
