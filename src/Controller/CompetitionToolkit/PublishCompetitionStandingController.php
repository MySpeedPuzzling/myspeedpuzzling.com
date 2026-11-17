<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\PublishCompetitionStanding;
use SpeedPuzzling\Web\Message\UnpublishCompetitionStanding;
use SpeedPuzzling\Web\Query\GetCompetitionStandings;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Publish / unpublish a standing (competition toolkit A7) from the standings page - one form, `publish` = 1 or 0. The
 * public page (event_standing / edition_standing) counts only rounds whose results are published, whatever this says.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class PublishCompetitionStandingController extends AbstractController
{
    public const string CSRF_TOKEN_PREFIX = 'publish-standing-';

    public function __construct(
        private readonly GetCompetitionStandings $getCompetitionStandings,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/poradi-udalosti/{competitionId}/{standingId}/zverejnit',
            'en' => '/en/manage-event-standings/{competitionId}/{standingId}/publish',
            'es' => '/es/manage-event-standings/{competitionId}/{standingId}/publish',
            'ja' => '/ja/manage-event-standings/{competitionId}/{standingId}/publish',
            'fr' => '/fr/manage-event-standings/{competitionId}/{standingId}/publish',
            'de' => '/de/manage-event-standings/{competitionId}/{standingId}/publish',
        ],
        name: 'publish_competition_standing',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT, 'standingId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $competitionId, string $standingId): Response
    {
        $standing = $this->getCompetitionStandings->byId($standingId);

        if ($standing->competitionId !== strtolower($competitionId)) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $standing->competitionId);

        if (!$this->isCsrfTokenValid(self::CSRF_TOKEN_PREFIX . $standing->id, $request->request->getString('_publish_token'))) {
            throw $this->createAccessDeniedException();
        }

        $publish = $request->request->getBoolean('publish');

        $this->messageBus->dispatch($publish
            ? new PublishCompetitionStanding($standing->id, $standing->competitionId)
            : new UnpublishCompetitionStanding($standing->id, $standing->competitionId));

        $this->addFlash('success', $this->translator->trans(
            $publish ? 'public_standing.flash.published' : 'public_standing.flash.unpublished',
            ['%name%' => $standing->name],
        ));

        return $this->redirectToRoute('competition_standings_manage', ['competitionId' => $standing->competitionId], Response::HTTP_SEE_OTHER);
    }
}
