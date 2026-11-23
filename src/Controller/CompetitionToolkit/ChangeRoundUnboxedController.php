<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundPuzzleNotFound;
use SpeedPuzzling\Web\Message\ChangeRoundUnboxed;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Services\OfficialResultsLiveUpdates;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The "Unboxed" choice on the round's puzzles page (docs/features/competitions-management/world-series-rules.md §7,
 * spws-unboxed-per-puzzle.md): `unboxed=1` every puzzle of the round, `unboxed=some` only the ticked
 * `unboxed_puzzles[]` (round puzzle ids), anything else none. Only for whom COMPETITION_TOOLKIT_MANAGE grants. Always back to the puzzles page
 * (a full-page POST never answers 200).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ChangeRoundUnboxedController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly OfficialResultsLiveUpdates $officialResultsLiveUpdates,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/kolo-bez-krabice/{roundId}',
            'en' => '/en/round-unboxed/{roundId}',
            'es' => '/es/round-unboxed/{roundId}',
            'ja' => '/ja/round-unboxed/{roundId}',
            'fr' => '/fr/round-unboxed/{roundId}',
            'de' => '/de/round-unboxed/{roundId}',
        ],
        name: 'change_round_unboxed',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $roundId): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $roundId = $round->id->toString();
        $competitionId = $round->competition->id->toString();
        $this->denyAccessUnlessGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $competitionId);

        $back = $this->redirectToRoute('manage_round_puzzles', ['roundId' => $roundId], Response::HTTP_SEE_OTHER);

        if ($this->isCsrfTokenValid('change_round_unboxed_' . $roundId, $request->request->getString('_token')) === false) {
            $this->addFlash('danger', $this->translator->trans('competition.reveal.flash.invalid'));

            return $back;
        }

        // `unboxed`: 1 = every puzzle of the round, `some` = only the ticked `unboxed_puzzles[]`, anything else = boxed
        $choice = $request->request->getString('unboxed');
        $unboxed = $choice === '1';
        $roundPuzzleIds = [];

        if ($choice === 'some') {
            /** @var array<mixed> $ticked */
            $ticked = $request->request->all('unboxed_puzzles');
            $roundPuzzleIds = array_values(array_unique(array_filter($ticked, is_string(...))));
        }

        try {
            $this->messageBus->dispatch(new ChangeRoundUnboxed($competitionId, $roundId, $unboxed, $roundPuzzleIds));
        } catch (CompetitionRoundPuzzleNotFound) {
            // A puzzle removed from the round meanwhile (another tab) - nothing was written (an HTTP exception comes
            // unwrapped - UnwrapHttpExceptionMiddleware)
            $this->addFlash('danger', $this->translator->trans('competition.round_puzzles.unboxed.puzzle_gone'));

            return $back;
        }

        // Open judge pages and the event's displays read the round's puzzles again (after the commit)
        $this->officialResultsLiveUpdates->puzzlesRevealed($roundId);

        $message = match (true) {
            $unboxed => 'competition.round_puzzles.unboxed.saved_on',
            $roundPuzzleIds !== [] => 'competition.round_puzzles.unboxed.saved_some',
            default => 'competition.round_puzzles.unboxed.saved_off',
        };
        $this->addFlash('success', $this->translator->trans($message, ['%count%' => count($roundPuzzleIds)]));

        return $back;
    }
}
