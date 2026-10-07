<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use SpeedPuzzling\Web\Message\DeleteCompetitionRound;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Entity\CompetitionRound;
use SpeedPuzzling\Web\Exceptions\OfficialResultsChangedMeanwhile;
use SpeedPuzzling\Web\Exceptions\SecretPuzzlesWouldBeRevealed;
use SpeedPuzzling\Web\Results\RoundResultEntry;
use SpeedPuzzling\Web\Services\OfficialResultsGuard;
use SpeedPuzzling\Web\Services\SecretRevealPreview;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class DeleteCompetitionRoundController extends AbstractController
{
    public function __construct(
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly TranslatorInterface $translator,
        private readonly SecretRevealPreview $secretRevealPreview,
        private readonly OfficialResultsGuard $officialResultsGuard,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/smazat-kolo-udalosti/{roundId}',
            'en' => '/en/delete-event-round/{roundId}',
            'es' => '/es/delete-event-round/{roundId}',
            'ja' => '/ja/delete-event-round/{roundId}',
            'fr' => '/fr/delete-event-round/{roundId}',
            'de' => '/de/delete-event-round/{roundId}',
        ],
        name: 'delete_competition_round',
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $roundId): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $competitionId = $round->competition->id->toString();
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        // The round's official results go with it - only on an explicit yes for exactly the entries shown
        $officialResults = $this->officialResultsGuard->entriesWithOfficialData($round->id->toString());
        $officialResultsHash = OfficialResultsGuard::hashEntries($officialResults);
        $officialResultsConfirmed = $officialResults === [] || (
            $request->request->get('confirm_official_results') === '1'
            && $request->request->get('confirm_official_results_hash') === $officialResultsHash
        );

        if ($officialResultsConfirmed === false) {
            return $this->askAboutOfficialResults($request, $round, $officialResults, $officialResultsHash);
        }

        // Deleting the round may let its secret puzzles out at once (other rounds reveal them already) - only on an
        // explicit yes for exactly the puzzles shown
        $revealed = $this->secretRevealPreview->byRemoving(array_values($round->roundPuzzles->toArray()));
        $confirmed = $request->request->get('confirm_reveal') === '1'
            && $request->request->get('confirm_reveal_hash') === SecretRevealPreview::hash($revealed);

        if ($revealed !== [] && $confirmed === false) {
            return $this->askFirst($request, $round, $revealed, $officialResults !== [] ? $officialResultsHash : null);
        }

        try {
            $this->messageBus->dispatch(new DeleteCompetitionRound(
                roundId: $roundId,
                // Re-checked after the handler's locks - another change in between asks again
                confirmedRevealHash: SecretRevealPreview::hash($revealed),
                confirmedOfficialResultsHash: $officialResultsHash,
            ));
        } catch (SecretPuzzlesWouldBeRevealed $changed) {
            return $this->askFirst($request, $this->competitionRoundRepository->get($roundId), $changed->puzzles, $officialResults !== [] ? $officialResultsHash : null);
        } catch (OfficialResultsChangedMeanwhile) {
            $round = $this->competitionRoundRepository->get($roundId);
            $officialResults = $this->officialResultsGuard->entriesWithOfficialData($round->id->toString());

            return $this->askAboutOfficialResults($request, $round, $officialResults, OfficialResultsGuard::hashEntries($officialResults));
        }

        if ($revealed !== []) {
            $this->addFlash('warning', $this->translator->trans('competition.reveal.flash.round_deleted_revealed', [
                '%puzzles%' => implode(', ', array_column($revealed, 'name')),
            ]));
        } else {
            $this->addFlash('success', $this->translator->trans('competition.flash.round_deleted'));
        }

        return $this->redirectToRoute('manage_competition_rounds', ['competitionId' => $competitionId]);
    }

    /**
     * @param list<RoundResultEntry> $officialResults
     */
    private function askAboutOfficialResults(Request $request, CompetitionRound $round, array $officialResults, string $hash): Response
    {
        return $this->render('competition/confirm_delete_round_official_results.html.twig', [
            'round' => $round,
            'entries' => $officialResults,
            'action' => $this->generateUrl('delete_competition_round', ['roundId' => $round->id->toString()]),
            'token' => (string) $request->request->get('_token'),
            'hash' => $hash,
            'cancel_url' => $this->generateUrl('manage_competition_rounds', ['competitionId' => $round->competition->id->toString()]),
        ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }

    /**
     * @param list<array{id: string, name: string, everywhere: bool, hiddenElsewhereUntil: null|\DateTimeImmutable}> $revealed
     * @param null|string $officialResultsHash the organiser's yes to losing the official results, carried along
     */
    private function askFirst(Request $request, CompetitionRound $round, array $revealed, null|string $officialResultsHash = null): Response
    {
        return $this->render('competition/confirm_reveal.html.twig', [
            'round' => $round,
            'revealed' => $revealed,
            'intro' => 'competition.reveal.confirm.delete_round_intro',
            'submit' => 'competition.reveal.confirm.submit_delete',
            'action' => $this->generateUrl('delete_competition_round', ['roundId' => $round->id->toString()]),
            'token' => (string) $request->request->get('_token'),
            'hash' => SecretRevealPreview::hash($revealed),
            'cancel_url' => $this->generateUrl('manage_competition_rounds', ['competitionId' => $round->competition->id->toString()]),
            'timezone' => $round->displayTimezone(),
            'extra_fields' => $officialResultsHash !== null ? [
                'confirm_official_results' => '1',
                'confirm_official_results_hash' => $officialResultsHash,
            ] : [],
        ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
    }
}
