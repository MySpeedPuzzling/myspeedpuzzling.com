<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplayLiveUpdates;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionStanding;
use SpeedPuzzling\Web\Query\GetCompetitionRoundsForManagement;
use SpeedPuzzling\Web\Query\GetCompetitionStandings;
use SpeedPuzzling\Web\Results\CompetitionStandingOverview;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Services\CompetitionToolkit\StandingsPerCategory;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "One standing per category" (competition toolkit A6) - the World Series case in one click: for every category with
 * rounds and no standing yet, a standing over all its rounds with the default rules ("Overall - Solo") - the rule is
 * StandingsPerCategory's (shared with the creation wizard). Each one is its own CreateCompetitionStanding.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class CreateStandingsPerCategoryController extends AbstractController
{
    public const string CSRF_TOKEN_PREFIX = 'standings-per-category-';

    public function __construct(
        private readonly GetCompetitionRoundsForManagement $getCompetitionRoundsForManagement,
        private readonly GetCompetitionStandings $getCompetitionStandings,
        private readonly MessageBusInterface $messageBus,
        private readonly CompetitionDisplayLiveUpdates $displayLiveUpdates,
        private readonly TranslatorInterface $translator,
        private readonly StandingsPerCategory $standingsPerCategory,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/poradi-udalosti/{competitionId}/podle-kategorii',
            'en' => '/en/manage-event-standings/{competitionId}/per-category',
            'es' => '/es/manage-event-standings/{competitionId}/per-category',
            'ja' => '/ja/manage-event-standings/{competitionId}/per-category',
            'fr' => '/fr/manage-event-standings/{competitionId}/per-category',
            'de' => '/de/manage-event-standings/{competitionId}/per-category',
        ],
        name: 'create_competition_standings_per_category',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $competitionId);

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_PREFIX . strtolower($competitionId), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        $covered = array_map(
            static fn (CompetitionStandingOverview $standing): RoundCategory => $standing->category,
            $this->getCompetitionStandings->ofCompetition($competitionId),
        );
        /** @var array<string, list<string>> $roundIdsByCategory */
        $roundIdsByCategory = [];

        foreach ($this->getCompetitionRoundsForManagement->ofCompetition($competitionId) as $round) {
            $roundIdsByCategory[$round->category->value][] = $round->id;
        }

        $created = 0;

        // Each its own message - the first refused one stops the rest
        try {
            foreach ($this->standingsPerCategory->messages($competitionId, $roundIdsByCategory, $covered) as $message) {
                $this->messageBus->dispatch($message);
                $created++;
            }
        } catch (InvalidCompetitionStanding $refused) {
            $this->addFlash('danger', $this->translator->trans($refused->translationKey()));
        }

        if ($created > 0) {
            $this->addFlash('success', $this->translator->trans('competition_standing.flash.added_per_category', ['%count%' => $created]));
        }

        // After the commit: the public standings' guest snapshots start a new generation (competition toolkit v2 S1)
        $this->displayLiveUpdates->standingsChanged($competitionId);
        return $this->redirectToRoute('competition_standings_manage', ['competitionId' => $competitionId], Response::HTTP_SEE_OTHER);
    }
}
