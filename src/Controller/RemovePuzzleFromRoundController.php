<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\CompetitionRoundPuzzle;
use SpeedPuzzling\Web\Message\RemovePuzzleFromCompetitionRound;
use SpeedPuzzling\Web\Repository\CompetitionRoundPuzzleRepository;
use SpeedPuzzling\Web\Repository\PuzzleRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\SecretRevealPreview;
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
        private readonly PuzzleRepository $puzzleRepository,
        private readonly TranslatorInterface $translator,
        private readonly ClockInterface $clock,
        private readonly ZonedDateTimeFormatter $zonedDateTimeFormatter,
        private readonly SecretRevealPreview $secretRevealPreview,
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
        $roundId = $round->id->toString();
        $timezone = $round->displayTimezone();
        $competitionId = $round->competition->id->toString();
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $backToPuzzles = $this->redirectToRoute('manage_round_puzzles', ['roundId' => $roundId], Response::HTTP_SEE_OTHER);

        if (!$this->isCsrfTokenValid('remove_puzzle_' . $roundPuzzleId, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', $this->translator->trans('competition.reveal.flash.invalid'));

            return $backToPuzzles;
        }

        // Removing may let a secret puzzle out at once (the rounds left reveal it already) - only on an explicit yes
        // for exactly the puzzles shown
        $revealed = $this->secretRevealPreview->byRemoving([$roundPuzzle]);
        $confirmed = $request->request->get('confirm_reveal') === '1'
            && $request->request->get('confirm_reveal_hash') === SecretRevealPreview::hash($revealed);

        if ($revealed !== [] && $confirmed === false) {
            return $this->render('competition/confirm_reveal.html.twig', [
                'round' => $round,
                'revealed' => $revealed,
                'intro' => 'competition.reveal.confirm.removal_intro',
                'submit' => 'competition.reveal.confirm.submit_remove',
                'action' => $this->generateUrl('remove_puzzle_from_round', ['roundPuzzleId' => $roundPuzzleId]),
                'token' => (string) $request->request->get('_token'),
                'hash' => SecretRevealPreview::hash($revealed),
                'cancel_url' => $this->generateUrl('manage_round_puzzles', ['roundId' => $roundId]),
                'timezone' => $timezone,
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
        }

        $puzzleId = $roundPuzzle->puzzle->id->toString();
        $puzzleName = $roundPuzzle->puzzle->name;

        $this->messageBus->dispatch(new RemovePuzzleFromCompetitionRound(roundPuzzleId: $roundPuzzleId));

        if ($revealed !== []) {
            $this->addFlash('warning', $this->translator->trans('competition.reveal.flash.removed_revealed', [
                '%puzzles%' => implode(', ', array_column($revealed, 'name')),
            ]));

            return $backToPuzzles;
        }

        // Never revealed by accident: the puzzle's site-wide hide follows the rounds left, and with none left it stays
        // as it was (SecretPuzzleHides) - say until when, as it is now (read again - the handler worked on fresh rows)
        $puzzle = $this->puzzleRepository->get($puzzleId);
        $now = $this->clock->now();
        $hiddenUntil = $puzzle->isHiddenAt($now) ? $puzzle->hideUntil : ($puzzle->isImageHiddenAt($now) ? $puzzle->hideImageUntil : null);

        if ($hiddenUntil === null) {
            $this->addFlash('success', $this->translator->trans('competition.flash.puzzle_removed'));
        } elseif ($hiddenUntil >= new DateTimeImmutable(CompetitionRoundPuzzle::HIDDEN_UNTIL_REVEALED)) {
            $this->addFlash('warning', $this->translator->trans('competition.reveal.flash.removed_stays_hidden_manual', [
                '%puzzle%' => $puzzleName,
            ]));
        } else {
            $this->addFlash('success', $this->translator->trans('competition.reveal.flash.removed_stays_hidden', [
                '%puzzle%' => $puzzleName,
                '%time%' => $this->zonedDateTimeFormatter->format($hiddenUntil, $timezone),
            ]));
        }

        return $backToPuzzles;
    }
}
