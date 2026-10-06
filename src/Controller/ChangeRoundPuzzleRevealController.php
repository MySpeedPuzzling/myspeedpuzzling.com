<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use DateTimeImmutable;
use DateTimeZone;
use SpeedPuzzling\Web\Message\ChangeRoundPuzzleReveal;
use SpeedPuzzling\Web\Repository\CompetitionRoundPuzzleRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The organiser's reveal choice for one round puzzle on the round's puzzles page: what stays secret and when it is
 * revealed (automatic / own time typed in the round's zone / manual).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ChangeRoundPuzzleRevealController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionRoundPuzzleRepository $competitionRoundPuzzleRepository,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/odhaleni-puzzle-kola/{roundPuzzleId}',
            'en' => '/en/round-puzzle-reveal/{roundPuzzleId}',
            'es' => '/es/round-puzzle-reveal/{roundPuzzleId}',
            'ja' => '/ja/round-puzzle-reveal/{roundPuzzleId}',
            'fr' => '/fr/round-puzzle-reveal/{roundPuzzleId}',
            'de' => '/de/round-puzzle-reveal/{roundPuzzleId}',
        ],
        name: 'change_round_puzzle_reveal',
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $roundPuzzleId): Response
    {
        $roundPuzzle = $this->competitionRoundPuzzleRepository->get($roundPuzzleId);
        $round = $roundPuzzle->round;
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $round->competition->id->toString());

        $backToPuzzles = $this->redirectToRoute('manage_round_puzzles', ['roundId' => $round->id->toString()], Response::HTTP_SEE_OTHER);

        if (!$this->isCsrfTokenValid('round_puzzle_reveal_' . $roundPuzzleId, (string) $request->request->get('_token'))) {
            $this->addFlash('danger', $this->translator->trans('competition.reveal.flash.invalid'));

            return $backToPuzzles;
        }

        $hideMode = PuzzleHideMode::tryFrom((string) $request->request->get('hide_mode'));
        $revealMode = RoundPuzzleReveal::tryFrom((string) $request->request->get('reveal_mode'));
        $scheduledAt = null;

        if ($revealMode === RoundPuzzleReveal::Scheduled) {
            // Typed as the round's local time, like the round's own start
            $scheduledAt = DateTimeImmutable::createFromFormat(
                '!Y-m-d\TH:i',
                (string) $request->request->get('reveal_at'),
                new DateTimeZone($round->displayTimezone()),
            );
        }

        if ($hideMode === null || $revealMode === null || $scheduledAt === false) {
            $this->addFlash('danger', $this->translator->trans('competition.reveal.flash.invalid'));

            return $backToPuzzles;
        }

        $this->messageBus->dispatch(new ChangeRoundPuzzleReveal(
            roundPuzzleId: $roundPuzzleId,
            hideMode: $hideMode,
            revealMode: $revealMode,
            scheduledAt: $scheduledAt?->setTimezone(new DateTimeZone('UTC')),
        ));

        $this->addFlash('success', $this->translator->trans('competition.reveal.flash.saved', [
            '%puzzle%' => $roundPuzzle->puzzle->name,
        ]));

        return $backToPuzzles;
    }
}
