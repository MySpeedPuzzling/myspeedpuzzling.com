<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetCompetitionAdvancementSteps;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionRoundsForManagement;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Results\AdvancementStepsView;
use SpeedPuzzling\Web\Results\Bracket\BracketRound;
use SpeedPuzzling\Web\Results\OfficialResultsRound;
use SpeedPuzzling\Web\Services\CompetitionToolkit\BracketLayout;
use SpeedPuzzling\Web\Services\OfficialResultsRounds;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The declared advancement steps of an event (competition toolkit A8, A-round-formats.md §3.5) - the "spider": which
 * rounds feed which, how many qualify, how they are spread; the stage of each round. A step that a later round change
 * broke is marked for the organiser; the bracket of the working steps with every round's results progress (A9). Only for
 * whom COMPETITION_TOOLKIT_MANAGE grants.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class AdvancementStepsController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly GetCompetitionRoundsForManagement $getCompetitionRoundsForManagement,
        private readonly GetCompetitionAdvancementSteps $getCompetitionAdvancementSteps,
        private readonly OfficialResultsRounds $officialResultsRounds,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/postup-udalosti/{competitionId}',
            'en' => '/en/manage-event-advancement/{competitionId}',
            'es' => '/es/manage-event-advancement/{competitionId}',
            'ja' => '/ja/manage-event-advancement/{competitionId}',
            'fr' => '/fr/manage-event-advancement/{competitionId}',
            'de' => '/de/manage-event-advancement/{competitionId}',
        ],
        name: 'competition_advancement_steps_manage',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $competitionId);

        $competition = $this->getCompetitionEvents->byId($competitionId);
        $rounds = $this->getCompetitionRoundsForManagement->ofCompetition($competitionId);
        $steps = $this->getCompetitionAdvancementSteps->ofCompetition($competitionId);

        // The bracket (competition toolkit A9) - only with steps: an event without them asks nothing more
        $brackets = [];
        $publicBracketUrl = null;
        if ($steps !== []) {
            $now = $this->clock->now();
            $reference = $this->getCompetitionEvents->referenceById($competitionId);
            $brackets = BracketLayout::of($steps, array_map(
                static fn (OfficialResultsRound $round): BracketRound => BracketRound::forOrganiser($round, $now),
                $this->officialResultsRounds->ofRounds($competitionId, $rounds, $reference),
            ));
            // The public page answers 404 while the event is a draft - no link to it then
            if ($competition->isDraft === false && $reference->slug !== null) {
                $publicBracketUrl = match ($reference->routeName()) {
                    'event_detail' => $this->generateUrl('event_bracket', ['slug' => $reference->slug]),
                    'edition_detail' => $this->generateUrl('edition_bracket', ['seriesSlug' => (string) $reference->seriesSlug, 'editionSlug' => $reference->slug]),
                    default => null,
                };
            }
        }

        $response = $this->render('competition_toolkit/advancement_steps.html.twig', [
            'competition' => $competition,
            'rounds' => $rounds,
            'view' => AdvancementStepsView::of($steps, $rounds),
            'brackets' => $brackets,
            'public_bracket_url' => $publicBracketUrl,
        ]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
