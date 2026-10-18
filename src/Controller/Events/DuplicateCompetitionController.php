<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Events;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Message\DuplicateCompetition;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Duplicate" of a one-time event or an edition (docs/features/competitions-management/README.md "Duplicating an
 * event") - from the ⋯ menu, "You organize" and the edit page. Always a 303 to the new draft's edit page, where the
 * organiser changes the dates and the venue before publishing it.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class DuplicateCompetitionController extends AbstractController
{
    // Stateless (config/packages/csrf.php): the button sits in every ⋯ menu and on "You organize" - a session-backed
    // token would write the session for each of them
    public const string CSRF_TOKEN_ID = 'competition_duplicate';

    public function __construct(
        readonly private MessageBusInterface $messageBus,
        readonly private RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        readonly private TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: '/{_locale}/duplicate-event/{competitionId}',
        name: 'duplicate_competition',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $competitionId = strtolower($competitionId);
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            throw $this->createAccessDeniedException();
        }

        $profile = $this->retrieveLoggedUserProfile->getProfile();

        if ($profile === null) {
            throw $this->createAccessDeniedException();
        }

        $newCompetitionId = Uuid::uuid7();

        $this->messageBus->dispatch(new DuplicateCompetition(
            newCompetitionId: $newCompetitionId,
            sourceCompetitionId: $competitionId,
            actingPlayerId: $profile->playerId,
        ));

        $this->addFlash('success', $this->translator->trans('event_duplicate.flash'));

        return $this->redirectToRoute(
            'edit_competition',
            ['competitionId' => $newCompetitionId->toString()],
            Response::HTTP_SEE_OTHER,
        );
    }
}
