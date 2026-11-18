<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\AnalyticsStudio;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Security\CompetitionResultsEntryVoter;
use SpeedPuzzling\Web\Services\AnalyticsStudio\AnalyticsStudioStateBuilder;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The analytics studio's state for the event's staff (analytics-studio.md "FU-ST"): the shell points a page rendered for
 * staff here instead of `analytics_studio_state`. On the normal (stateful) firewall - the session tells who asks; the
 * path ends in `/staff-state`, outside the stateless firewall's `/state` pattern. Staff = may enter the event's results
 * (CompetitionResultsEntryVoter: whoever may manage the event, its referees, admins), asked again on every request; they
 * see the live ranking of an unpublished round whatever the live results setting. Anybody else (signed out, a
 * stranger, a referee removed meanwhile) gets the public answer - never an error, nothing more.
 *
 * Headers as the public state: `private, no-store` - neither a browser nor a shared cache keeps it.
 */
final class AnalyticsStudioStaffStateController extends AbstractController
{
    public function __construct(
        private readonly AnalyticsStudioStateBuilder $stateBuilder,
    ) {
    }

    #[Route(
        path: '/analytics-studio/{roundId}/staff-state',
        name: 'analytics_studio_staff_state',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
        format: 'json',
    )]
    public function __invoke(string $roundId): JsonResponse
    {
        $state = $this->stateBuilder->buildForViewer(
            $roundId,
            fn (string $competitionId): bool => $this->isGranted(CompetitionResultsEntryVoter::COMPETITION_RESULTS_ENTRY, $competitionId),
        );

        $response = new JsonResponse($state);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');

        return $response;
    }
}
