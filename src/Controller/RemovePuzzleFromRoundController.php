<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Message\RemovePuzzleFromCompetitionRound;
use SpeedPuzzling\Web\Repository\CompetitionRoundPuzzleRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\ZonedDateTimeFormatter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class RemovePuzzleFromRoundController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionRoundPuzzleRepository $competitionRoundPuzzleRepository,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly ZonedDateTimeFormatter $zonedDateTimeFormatter,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/odebrat-puzzle-z-kola/{roundPuzzleId}',
            'en' => '/en/remove-puzzle-from-round/{roundPuzzleId}',
            'es' => '/es/remove-puzzle-from-round/{roundPuzzleId}',
            'ja' => '/ja/remove-puzzle-from-round/{roundPuzzleId}',
            'fr' => '/fr/remove-puzzle-from-round/{roundPuzzleId}',
            'de' => '/de/remove-puzzle-from-round/{roundPuzzleId}',
        ],
        name: 'remove_puzzle_from_round',
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $roundPuzzleId): Response
    {
        $roundPuzzle = $this->competitionRoundPuzzleRepository->get($roundPuzzleId);
        $round = $roundPuzzle->round;
        $competitionId = $round->competition->id->toString();
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $backToPuzzles = $this->redirectToRoute('manage_round_puzzles', ['roundId' => $round->id->toString()], Response::HTTP_SEE_OTHER);

        if (!$this->isCsrfTokenValid('remove_puzzle_' . $roundPuzzleId, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', $this->translator->trans('competition.reveal.flash.invalid'));

            return $backToPuzzles;
        }

        $puzzle = $roundPuzzle->puzzle;

        $this->messageBus->dispatch(new RemovePuzzleFromCompetitionRound(roundPuzzleId: $roundPuzzleId));

        // Removing never reveals: the puzzle's site-wide hide now follows the rounds left, and with none left it stays
        // as it was (SecretPuzzleHides) - say until when, as it is now
        $now = $this->clock->now();
        $hiddenUntil = $puzzle->isHiddenAt($now) ? $puzzle->hideUntil : ($puzzle->isImageHiddenAt($now) ? $puzzle->hideImageUntil : null);

        if ($hiddenUntil === null) {
            $this->addFlash('success', $this->translator->trans('competition.flash.puzzle_removed'));
        } elseif ($hiddenUntil >= new DateTimeImmutable(CompetitionRoundPuzzle::HIDDEN_UNTIL_REVEALED)) {
            $this->addFlash('warning', $this->translator->trans('competition.reveal.flash.removed_stays_hidden_manual', [
                '%puzzle%' => $puzzle->name,
            ]));
        } else {
            $this->addFlash('success', $this->translator->trans('competition.reveal.flash.removed_stays_hidden', [
                '%puzzle%' => $puzzle->name,
                '%time%' => $this->zonedDateTimeFormatter->format($hiddenUntil, $round->displayTimezone()),
            ]));
        }

        return $backToPuzzles;
    }
}
