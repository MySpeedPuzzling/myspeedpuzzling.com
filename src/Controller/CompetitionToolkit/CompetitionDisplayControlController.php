<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Query\GetCompetitionDisplays;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetRoundResultsOverview;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Services\LiveResultsCurrentRound;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Csrf\CsrfTokenManagerInterface;
use Symfony\Component\Security\Http\Attribute\IsGranted;

/**
 * The live control of an event's displays (competition toolkit v2 L1/L2, displays-standings.md §5.3) - a phone-first
 * page for the stream operator: the message every display with a Message element shows, the spotlight of a round, and a
 * preview of one display. A data-free shell: display_control_controller.js fills it from
 * competition_display_control_state and writes through set_display_message / set_round_spotlight. Organisers only
 * (COMPETITION_TOOLKIT_MANAGE).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class CompetitionDisplayControlController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly GetRoundResultsOverview $getRoundResultsOverview,
        private readonly GetCompetitionDisplays $getCompetitionDisplays,
        private readonly CsrfTokenManagerInterface $csrfTokenManager,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/ovladani-obrazovek/{competitionId}',
            'en' => '/en/display-control/{competitionId}',
            'es' => '/es/display-control/{competitionId}',
            'ja' => '/ja/display-control/{competitionId}',
            'fr' => '/fr/display-control/{competitionId}',
            'de' => '/de/display-control/{competitionId}',
        ],
        name: 'competition_display_control',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $competitionId);

        $competition = $this->getCompetitionEvents->byId($competitionId);
        $rounds = $this->getRoundResultsOverview->forCompetition($competitionId);
        $requested = $request->query->getString('round');
        $round = null;

        foreach ($rounds as $candidate) {
            if (strtolower($candidate->roundId) === strtolower($requested)) {
                $round = $candidate;
            }
        }

        $round ??= LiveResultsCurrentRound::pick($rounds, $this->clock->now());

        $response = $this->render('competition_toolkit/display_control.html.twig', [
            'competition' => $competition,
            'rounds' => $rounds,
            'round_id' => $round?->roundId,
            'displays' => $this->getCompetitionDisplays->ofCompetition($competitionId),
            'csrf_token' => $this->csrfTokenManager->getToken(OfficialResultsApi::CSRF_TOKEN_ID)->getValue(),
        ]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
