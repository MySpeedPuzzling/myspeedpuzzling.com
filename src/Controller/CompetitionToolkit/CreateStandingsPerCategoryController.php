<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionStanding;
use SpeedPuzzling\Web\Message\CreateCompetitionStanding;
use SpeedPuzzling\Web\Query\GetCompetitionRoundsForManagement;
use SpeedPuzzling\Web\Query\GetCompetitionStandings;
use SpeedPuzzling\Web\Results\CompetitionStandingOverview;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Value\RoundCategory;
use SpeedPuzzling\Web\Value\StandingRules;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "One standing per category" (competition toolkit A6) - the World Series case in one click: for every category with
 * rounds and no standing yet, a standing over all its rounds with the default rules ("Overall - Solo"). Each one is its
 * own CreateCompetitionStanding.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class CreateStandingsPerCategoryController extends AbstractController
{
    public const string CSRF_TOKEN_PREFIX = 'standings-per-category-';

    public function __construct(
        private readonly GetCompetitionRoundsForManagement $getCompetitionRoundsForManagement,
        private readonly GetCompetitionStandings $getCompetitionStandings,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
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
            if (!in_array($round->category, $covered, true)) {
                $roundIdsByCategory[$round->category->value][] = $round->id;
            }
        }

        $created = 0;

        foreach (RoundCategory::cases() as $category) {
            $roundIds = $roundIdsByCategory[$category->value] ?? [];

            if ($roundIds === []) {
                continue;
            }

            try {
                $this->messageBus->dispatch(new CreateCompetitionStanding(
                    standingId: Uuid::uuid7(),
                    competitionId: $competitionId,
                    name: $this->translator->trans('competition_standing.per_category_name', [
                        '%category%' => $this->translator->trans('competition.round.category.' . $category->value),
                    ]),
                    category: $category,
                    roundIds: $roundIds,
                    rules: new StandingRules(),
                ));
                $created++;
            } catch (InvalidCompetitionStanding $refused) {
                $this->addFlash('danger', $this->translator->trans($refused->translationKey()));

                break;
            }
        }

        if ($created > 0) {
            $this->addFlash('success', $this->translator->trans('competition_standing.flash.added_per_category', ['%count%' => $created]));
        }

        return $this->redirectToRoute('competition_standings_manage', ['competitionId' => $competitionId], Response::HTTP_SEE_OTHER);
    }
}
