<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\RoundPuzzleAlreadyRevealed;
use SpeedPuzzling\Web\Exceptions\RoundPuzzleCannotHideEverywhere;
use SpeedPuzzling\Web\Message\KeepRoundPuzzleHiddenEverywhere;
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
final class KeepRoundPuzzleHiddenEverywhereController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionRoundPuzzleRepository $competitionRoundPuzzleRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/skryt-puzzle-kola-vsude/{roundPuzzleId}',
            'en' => '/en/keep-round-puzzle-hidden/{roundPuzzleId}',
            'es' => '/es/keep-round-puzzle-hidden/{roundPuzzleId}',
            'ja' => '/ja/keep-round-puzzle-hidden/{roundPuzzleId}',
            'fr' => '/fr/keep-round-puzzle-hidden/{roundPuzzleId}',
            'de' => '/de/keep-round-puzzle-hidden/{roundPuzzleId}',
        ],
        name: 'keep_round_puzzle_hidden_everywhere',
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $roundPuzzleId): Response
    {
        $roundPuzzle = $this->competitionRoundPuzzleRepository->get($roundPuzzleId);
        $roundId = $roundPuzzle->round->id->toString();
        $puzzleName = $roundPuzzle->puzzle->name;
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $roundPuzzle->round->competition->id->toString());

        if (!$this->isCsrfTokenValid('keep_round_puzzle_hidden_' . $roundPuzzleId, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', $this->translator->trans('competition.reveal.flash.invalid'));
        } else {
            try {
                $this->messageBus->dispatch(new KeepRoundPuzzleHiddenEverywhere($roundPuzzleId));
                $this->addFlash('success', $this->translator->trans('competition.reveal.flash.kept_everywhere', [
                    '%puzzle%' => $puzzleName,
                ]));
            } catch (RoundPuzzleAlreadyRevealed) {
                $this->addFlash('danger', $this->translator->trans('competition.reveal.flash.already_revealed'));
            } catch (RoundPuzzleCannotHideEverywhere) {
                $this->addFlash('danger', $this->translator->trans('competition.reveal.flash.keep_everywhere_refused'));
            }
        }

        return $this->redirectToRoute('manage_round_puzzles', ['roundId' => $roundId], Response::HTTP_SEE_OTHER);
    }
}
