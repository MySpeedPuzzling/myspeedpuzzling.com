<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
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
 * The "Unboxed" switch on the round's puzzles page (docs/features/competitions-management/world-series-rules.md §7):
 * `unboxed=1` on, anything else off. Only for whom COMPETITION_TOOLKIT_MANAGE grants. Always back to the puzzles page
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

        $unboxed = $request->request->getString('unboxed') === '1';

        $this->messageBus->dispatch(new ChangeRoundUnboxed($competitionId, $roundId, $unboxed));

        // Open judge pages and the event's displays read the round's puzzles again (after the commit)
        $this->officialResultsLiveUpdates->puzzlesRevealed($roundId);

        $this->addFlash('success', $this->translator->trans($unboxed ? 'competition.round_puzzles.unboxed.saved_on' : 'competition.round_puzzles.unboxed.saved_off'));

        return $back;
    }
}
