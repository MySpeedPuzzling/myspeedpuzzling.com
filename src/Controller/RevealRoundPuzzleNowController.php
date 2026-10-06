<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Exceptions\RoundPuzzleAlreadyRevealed;
use SpeedPuzzling\Web\Message\RevealRoundPuzzleNow;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Services\ZonedDateTimeFormatter;
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
        private readonly PuzzleRepository $puzzleRepository,
        private readonly ClockInterface $clock,
        private readonly ZonedDateTimeFormatter $zonedDateTimeFormatter,
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
        $roundId = $round->id->toString();
        $timezone = $round->displayTimezone();
        $puzzleId = $roundPuzzle->puzzle->id->toString();
        $puzzleName = $roundPuzzle->puzzle->name;
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $round->competition->id->toString());

        if ($this->isCsrfTokenValid('reveal_round_puzzle_' . $roundPuzzleId, (string) $request->request->get('_token')) === false) {
            $this->addFlash('danger', $this->translator->trans('competition.reveal.flash.invalid'));
        } else {
            try {
                $this->messageBus->dispatch(new RevealRoundPuzzleNow(roundPuzzleId: $roundPuzzleId));
                $this->flashRevealed($puzzleId, $puzzleName, $timezone);
            } catch (RoundPuzzleAlreadyRevealed) {
                $this->addFlash('danger', $this->translator->trans('competition.reveal.flash.already_revealed'));
            }
        }

        return $this->redirectToRoute('manage_round_puzzles', ['roundId' => $roundId], Response::HTTP_SEE_OTHER);
    }

    /**
     * True to what happened: revealed on this round - and everywhere, unless another round keeps it hidden (read again,
     * the handler worked on fresh rows).
     */
    private function flashRevealed(string $puzzleId, string $puzzleName, string $timezone): void
    {
        $puzzle = $this->puzzleRepository->get($puzzleId);
        $now = $this->clock->now();
        $hiddenUntil = $puzzle->isHiddenAt($now) ? $puzzle->hideUntil : ($puzzle->isImageHiddenAt($now) ? $puzzle->hideImageUntil : null);

        if ($hiddenUntil === null) {
            $this->addFlash('success', $this->translator->trans('competition.reveal.flash.revealed', ['%puzzle%' => $puzzleName]));
        } elseif ($hiddenUntil >= new DateTimeImmutable(CompetitionRoundPuzzle::HIDDEN_UNTIL_REVEALED)) {
            $this->addFlash('warning', $this->translator->trans('competition.reveal.flash.revealed_held_no_time', ['%puzzle%' => $puzzleName]));
        } else {
            $this->addFlash('warning', $this->translator->trans('competition.reveal.flash.revealed_held', [
                '%puzzle%' => $puzzleName,
                '%time%' => $this->zonedDateTimeFormatter->format($hiddenUntil, $timezone),
            ]));
        }
    }
}
