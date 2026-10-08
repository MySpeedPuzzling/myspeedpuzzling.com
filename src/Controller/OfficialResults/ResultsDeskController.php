<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OfficialResults;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetRoundResultEntries;
use SpeedPuzzling\Web\Query\IsCompetitionPubliclyVisible;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\OfficialResultsLiveUpdates;
use SpeedPuzzling\Web\Services\OfficialResultsRounds;
use SpeedPuzzling\Web\Services\OfficialResultsSubscription;
use SpeedPuzzling\Web\Value\CountryCode;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The results desk of one round (docs/features/competitions-management/results-desk.md): the ranked table with
 * inline edits, qualified marks and the qualification helpers, advancing the qualified, publishing, the export.
 * Desktop/tablet-first, the event's organisers only. The page bootstraps the same state the JSON endpoint
 * `official_results_round_state` answers and follows the round's private Mercure topic with the subscription in it
 * (OfficialResultsSubscription); every write goes through the official results JSON endpoints.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class ResultsDeskController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $roundRepository,
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly GetRoundResultEntries $getRoundResultEntries,
        private readonly OfficialResultsRounds $officialResultsRounds,
        private readonly OfficialResultsSubscription $subscription,
        private readonly ClockInterface $clock,
        private readonly IsCompetitionPubliclyVisible $isCompetitionPubliclyVisible,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/sprava-vysledku-kola/{roundId}',
            'en' => '/en/manage-round-results/{roundId}',
            'es' => '/es/manage-round-results/{roundId}',
            'ja' => '/ja/manage-round-results/{roundId}',
            'fr' => '/fr/manage-round-results/{roundId}',
            'de' => '/de/manage-round-results/{roundId}',
        ],
        name: 'results_desk',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
    )]
    public function __invoke(string $roundId): Response
    {
        $round = $this->roundRepository->get($roundId);
        $competitionId = $round->competition->id->toString();
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $roundId = $round->id->toString();

        $competition = $this->getCompetitionEvents->byId($competitionId);
        $rounds = $this->officialResultsRounds->forCompetition($competitionId);

        $thisRound = null;
        foreach ($rounds as $candidate) {
            if ($candidate->id() === $roundId) {
                $thisRound = $candidate;
            }
        }

        if ($thisRound === null) {
            throw $this->createNotFoundException();
        }

        $isPubliclyVisible = $this->isCompetitionPubliclyVisible->check($competitionId);

        $countries = [];
        foreach (CountryCode::cases() as $country) {
            $countries[$country->name] = $country->value;
        }

        $response = $this->render('official_results/results_desk.html.twig', [
            'competition' => $competition,
            'round' => $thisRound,
            'rounds' => $rounds,
            // A draft (or an edition of a draft series) says so instead of the approval wording
            // (docs/features/organizations/README.md "Drafts") - asked only of an event that is not public
            'is_draft' => $isPubliclyVisible === false && $round->competition->isHiddenAsDraft(),
            'state' => [
                'serverNow' => $this->clock->now()->format(\DateTimeInterface::ATOM),
                'topic' => OfficialResultsLiveUpdates::topic($roundId),
                'competition' => [
                    'id' => $competition->id,
                    'name' => $competition->name,
                    'isOnline' => $competition->isOnline,
                    // Publishing on an event nobody can see yet tells nobody until it is approved
                    'isPubliclyVisible' => $isPubliclyVisible,
                ],
                'round' => $thisRound,
                'rounds' => $rounds,
                'entries' => $this->getRoundResultEntries->forRound($roundId),
                'mercure' => $this->subscription->forRound($roundId),
            ],
            'countries' => $countries,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
