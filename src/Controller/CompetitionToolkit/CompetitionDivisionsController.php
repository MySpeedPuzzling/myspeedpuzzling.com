<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Entity\CompetitionDivision;
use SpeedPuzzling\Web\Exceptions\DivisionsInUse;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionDivisions;
use SpeedPuzzling\Web\Message\SetCompetitionDivisions;
use SpeedPuzzling\Web\Query\GetCompetitionDivisions;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionRoundsForManagement;
use SpeedPuzzling\Web\Results\CompetitionDivisionOverview;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Services\OfficialResultsLiveUpdates;
use SpeedPuzzling\Web\Value\DivisionInput;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The divisions of an event (shared-puzzles-and-divisions.md §3, D1): its list - name, short label, order - and a
 * checkbox grid of which round uses which division. One form, one write (SetCompetitionDivisions); a division gets its
 * column in the grid once saved. Emptying a name removes the division. Taking a division from entries that carry it
 * asks first (DivisionsInUse → the page again with 422 and "Save and clear them"). Saved = redirect back; the open desks
 * of the rounds that changed are refreshed after the commit.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class CompetitionDivisionsController extends AbstractController
{
    // Empty rows offered for new divisions, up to the event's maximum
    private const int NEW_ROWS = 3;

    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly GetCompetitionRoundsForManagement $getCompetitionRoundsForManagement,
        private readonly GetCompetitionDivisions $getCompetitionDivisions,
        private readonly MessageBusInterface $messageBus,
        private readonly OfficialResultsLiveUpdates $officialResultsLiveUpdates,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/nastroje-udalosti/{competitionId}/divize',
            'en' => '/en/manage-event-toolkit/{competitionId}/divisions',
            'es' => '/es/manage-event-toolkit/{competitionId}/divisions',
            'ja' => '/ja/manage-event-toolkit/{competitionId}/divisions',
            'fr' => '/fr/manage-event-toolkit/{competitionId}/divisions',
            'de' => '/de/manage-event-toolkit/{competitionId}/divisions',
        ],
        name: 'competition_divisions',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $competitionId);

        $competition = $this->getCompetitionEvents->byId($competitionId);
        $rounds = $this->getCompetitionRoundsForManagement->ofCompetition($competitionId);
        $saved = $this->getCompetitionDivisions->ofCompetition($competitionId);

        $rows = array_map(static fn (CompetitionDivisionOverview $division): array => [
            'id' => $division->id,
            'name' => $division->name,
            'shortName' => $division->shortName ?? '',
            'position' => $division->position + 1,
        ], $saved);
        $ticks = [];
        foreach ($saved as $division) {
            foreach ($division->roundIds as $roundId) {
                $ticks[$roundId][$division->id] = true;
            }
        }

        $error = null;
        $inUse = null;

        if ($request->isMethod('POST')) {
            if ($this->isCsrfTokenValid('competition-divisions-' . $competitionId, $request->request->getString('_token')) === false) {
                throw $this->createAccessDeniedException();
            }

            [$rows, $ticks] = self::submitted($request, $saved);

            try {
                $envelope = $this->messageBus->dispatch(new SetCompetitionDivisions(
                    competitionId: $competitionId,
                    divisions: array_values(array_map(
                        static fn (array $row): DivisionInput => new DivisionInput($row['id'] ?? Uuid::uuid7()->toString(), $row['name'], $row['shortName']),
                        array_filter($rows, static fn (array $row): bool => trim($row['name']) !== ''),
                    )),
                    rounds: array_map(static fn (array $divisionIds): array => array_map(strval(...), array_keys($divisionIds)), $ticks),
                    confirmClearing: $request->request->getBoolean('confirm_clearing'),
                ));

                // After the commit: the open desks of the rounds that changed read the round again
                /** @var list<string> $changedRounds */
                $changedRounds = $envelope->last(HandledStamp::class)?->getResult() ?? [];
                foreach ($changedRounds as $roundId) {
                    $this->officialResultsLiveUpdates->refresh($roundId);
                }

                $this->addFlash('success', $this->translator->trans('competition_toolkit.divisions.saved'));

                return $this->redirectToRoute('competition_divisions', ['competitionId' => $competitionId], Response::HTTP_SEE_OTHER);
            } catch (InvalidCompetitionDivisions $refused) {
                $error = $this->translator->trans($refused->translationKey(), [
                    '%subject%' => $refused->subject ?? '',
                    '%max%' => CompetitionDivision::MAX_PER_COMPETITION,
                ]);
            } catch (DivisionsInUse $inUseException) {
                $inUse = $inUseException->count;
            }
        }

        // Empty rows for new divisions, while the event has room for them
        for ($added = 0; $added < self::NEW_ROWS && count($rows) < CompetitionDivision::MAX_PER_COMPETITION; $added++) {
            $rows[] = ['id' => null, 'name' => '', 'shortName' => '', 'position' => count($rows) + 1];
        }

        $response = $this->render('competition_toolkit/divisions.html.twig', [
            'competition' => $competition,
            'rounds' => $rounds,
            'rows' => $rows,
            // Grid columns: the divisions saved so far
            'divisions' => $saved,
            'ticks' => $ticks,
            'error' => $error,
            'in_use' => $inUse,
            'max_divisions' => CompetitionDivision::MAX_PER_COMPETITION,
        ], new Response(status: $error !== null || $inUse !== null ? Response::HTTP_UNPROCESSABLE_ENTITY : Response::HTTP_OK));
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }

    /**
     * The rows and ticks as posted, rows in their order (then as listed). Ids are kept only for the event's own saved
     * divisions - anything else is a new division.
     *
     * @param list<CompetitionDivisionOverview> $saved
     * @return array{list<array{id: null|string, name: string, shortName: string, position: int}>, array<string, array<string, true>>}
     */
    private static function submitted(Request $request, array $saved): array
    {
        $savedIds = array_fill_keys(array_map(static fn (CompetitionDivisionOverview $division): string => $division->id, $saved), true);

        $rows = [];
        $posted = $request->request->all('divisions');
        foreach (array_values($posted) as $index => $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = is_string($row['id'] ?? null) ? strtolower($row['id']) : '';
            $position = is_numeric($row['position'] ?? null) ? (int) $row['position'] : $index + 1;
            $rows[] = [
                'id' => isset($savedIds[$id]) ? $id : null,
                'name' => is_string($row['name'] ?? null) ? $row['name'] : '',
                'shortName' => is_string($row['shortName'] ?? null) ? $row['shortName'] : '',
                'position' => $position,
                'index' => $index,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [$a['position'], $a['index']] <=> [$b['position'], $b['index']]);
        $rows = array_map(static fn (array $row): array => ['id' => $row['id'], 'name' => $row['name'], 'shortName' => $row['shortName'], 'position' => $row['position']], $rows);

        $ticks = [];
        foreach ($request->request->all('rounds') as $roundId => $divisionIds) {
            if (!is_array($divisionIds)) {
                continue;
            }

            foreach ($divisionIds as $divisionId) {
                // A tick of a division whose row was emptied (removed) goes with it
                if (is_string($divisionId) && isset($savedIds[strtolower($divisionId)])) {
                    $ticks[(string) $roundId][strtolower($divisionId)] = true;
                }
            }
        }

        $kept = [];
        foreach ($rows as $row) {
            if ($row['id'] !== null && trim($row['name']) !== '') {
                $kept[$row['id']] = true;
            }
        }

        foreach ($ticks as $roundId => $divisionIds) {
            $ticks[$roundId] = array_intersect_key($divisionIds, $kept);
        }

        return [$rows, $ticks];
    }
}
