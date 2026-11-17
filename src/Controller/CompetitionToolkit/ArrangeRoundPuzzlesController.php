<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\ArrangeRoundPuzzles;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Services\OfficialResultsLiveUpdates;
use SpeedPuzzling\Web\Value\RoundPuzzleFormat;
use SpeedPuzzling\Web\Value\RoundPuzzleLabel;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Order and labels" on the round's puzzles page (competition toolkit A2): `position[<roundPuzzleId>]` (any numbers -
 * the puzzles are put in their order, ties keep the order they had) and `label[<roundPuzzleId>]`. Only for whom
 * COMPETITION_TOOLKIT_MANAGE grants. Always back to the puzzles page (a full-page POST never answers 200).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ArrangeRoundPuzzlesController extends AbstractController
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
            'cs' => '/seradit-puzzle-kola/{roundId}',
            'en' => '/en/arrange-round-puzzles/{roundId}',
            'es' => '/es/arrange-round-puzzles/{roundId}',
            'ja' => '/ja/arrange-round-puzzles/{roundId}',
            'fr' => '/fr/arrange-round-puzzles/{roundId}',
            'de' => '/de/arrange-round-puzzles/{roundId}',
        ],
        name: 'arrange_round_puzzles',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $roundId): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $roundId = $round->id->toString();
        $this->denyAccessUnlessGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $round->competition->id->toString());

        $back = $this->redirectToRoute('manage_round_puzzles', ['roundId' => $roundId], Response::HTTP_SEE_OTHER);

        if ($this->isCsrfTokenValid('arrange_round_puzzles_' . $roundId, $request->request->getString('_token')) === false) {
            $this->addFlash('danger', $this->translator->trans('competition.reveal.flash.invalid'));

            return $back;
        }

        $positions = $request->request->all('position');
        $labels = [];

        foreach ($request->request->all('label') as $roundPuzzleId => $label) {
            if (is_string($label)) {
                $labels[strtolower((string) $roundPuzzleId)] = $label;
            }
        }

        foreach ($labels as $label) {
            if (mb_strlen(trim($label)) > RoundPuzzleLabel::MAX_LENGTH) {
                $this->addFlash('danger', $this->translator->trans('competition_toolkit.round_format.arrange.label_too_long', ['%max%' => RoundPuzzleLabel::MAX_LENGTH]));

                return $back;
            }
        }

        // The typed numbers decide the order; a puzzle without a number keeps its place after the numbered ones
        $order = [];
        $index = 0;
        foreach ($positions as $roundPuzzleId => $position) {
            if (is_numeric($position)) {
                $order[strtolower((string) $roundPuzzleId)] = [(float) $position, $index];
            }
            $index++;
        }
        uasort($order, static fn (array $a, array $b): int => $a <=> $b);

        $this->messageBus->dispatch(new ArrangeRoundPuzzles(
            roundId: $roundId,
            competitionId: $round->competition->id->toString(),
            roundPuzzleIds: array_map(strval(...), array_keys($order)),
            labels: $labels,
        ));

        // Open judge pages of a `pick` round read its labels again (after the commit)
        if ($round->puzzleFormat === RoundPuzzleFormat::Pick) {
            $this->officialResultsLiveUpdates->puzzlesRevealed($roundId);
        }

        $this->addFlash('success', $this->translator->trans('competition_toolkit.round_format.arrange.saved'));

        return $back;
    }
}
