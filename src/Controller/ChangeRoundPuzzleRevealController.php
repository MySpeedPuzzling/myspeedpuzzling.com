<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Exceptions\InvalidLocalTime;
use SpeedPuzzling\Web\Exceptions\PuzzleHiddenByHand;
use SpeedPuzzling\Web\Exceptions\PuzzleNameAlreadyPublic;
use SpeedPuzzling\Web\Exceptions\RevealMomentAlreadyPassed;
use SpeedPuzzling\Web\Exceptions\RoundPuzzleAlreadyRevealed;
use SpeedPuzzling\Web\Exceptions\RoundPuzzleAlreadyShown;
use SpeedPuzzling\Web\Message\ChangeRoundPuzzleReveal;
use SpeedPuzzling\Web\Repository\CompetitionRoundPuzzleRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Value\PuzzleHideMode;
use SpeedPuzzling\Web\Value\RoundPuzzleReveal;
use SpeedPuzzling\Web\Value\RoundTimezone;
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

        $puzzleName = $roundPuzzle->puzzle->name;
        $hideMode = PuzzleHideMode::tryFrom((string) $request->request->get('hide_mode'));
        $revealMode = RoundPuzzleReveal::tryFrom((string) $request->request->get('reveal_mode'));

        if ($hideMode === null || $revealMode === null) {
            return $this->refused($request, $roundPuzzleId, $round->id->toString(), 'competition.reveal.flash.invalid');
        }

        $scheduledAt = null;

        if ($revealMode === RoundPuzzleReveal::Scheduled) {
            try {
                // Typed as the round's local time, like the round's own start - only a time that exists exactly once
                $scheduledAt = RoundTimezone::parseLocal(
                    (string) $request->request->get('reveal_at'),
                    'Y-m-d\\TH:i',
                    $round->displayTimezone(),
                );
            } catch (InvalidLocalTime) {
                return $this->refused($request, $roundPuzzleId, $round->id->toString(), 'competition.reveal.flash.invalid_time');
            }
        }

        try {
            $this->messageBus->dispatch(new ChangeRoundPuzzleReveal(
                roundPuzzleId: $roundPuzzleId,
                hideMode: $hideMode,
                revealMode: $revealMode,
                scheduledAt: $scheduledAt,
            ));
        } catch (RevealMomentAlreadyPassed) {
            return $this->refused($request, $roundPuzzleId, $round->id->toString(), 'competition.reveal.flash.time_passed');
        } catch (RoundPuzzleAlreadyRevealed) {
            return $this->refused($request, $roundPuzzleId, $round->id->toString(), 'competition.reveal.flash.already_revealed');
        } catch (RoundPuzzleAlreadyShown) {
            return $this->refused($request, $roundPuzzleId, $round->id->toString(), 'competition.reveal.flash.already_shown');
        } catch (PuzzleNameAlreadyPublic) {
            return $this->refused($request, $roundPuzzleId, $round->id->toString(), 'competition.reveal.flash.name_already_public');
        } catch (PuzzleHiddenByHand) {
            return $this->refused($request, $roundPuzzleId, $round->id->toString(), 'competition.reveal.flash.puzzle_hidden_by_hand');
        }

        $this->addFlash('success', $this->translator->trans('competition.reveal.flash.saved', [
            '%puzzle%' => $puzzleName,
        ]));

        return $backToPuzzles;
    }

    /**
     * The round's puzzles page again (422), the refused card open with the error and with what was typed - nothing is
     * lost and nothing was saved.
     */
    private function refused(Request $request, string $roundPuzzleId, string $roundId, string $messageKey): Response
    {
        $response = $this->forward(ManageRoundPuzzlesController::class, [
            'roundId' => $roundId,
            'revealError' => [
                'roundPuzzleId' => $roundPuzzleId,
                'message' => $this->translator->trans($messageKey),
                'hideMode' => (string) $request->request->get('hide_mode'),
                'revealMode' => (string) $request->request->get('reveal_mode'),
                'revealAt' => (string) $request->request->get('reveal_at'),
            ],
        ]);
        $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);

        return $response;
    }
}
