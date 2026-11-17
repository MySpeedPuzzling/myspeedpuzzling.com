<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Entity\CompetitionDisplay;
use SpeedPuzzling\Web\Exceptions\CompetitionDisplayLimitReached;
use SpeedPuzzling\Web\Message\DuplicateCompetitionDisplay;
use SpeedPuzzling\Web\Query\GetCompetitionDisplays;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplayActions;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A copy of a display with a new link of its own (D-displays-overlays.md §8.1) - a button on the display's row of the
 * hub card and the displays page. Done = back to the form's `return` (else the displays page) with a flash. The voter
 * is checked on the event of the display loaded from the database, never on an id from the request.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class DuplicateCompetitionDisplayController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionDisplays $getCompetitionDisplays,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/editor-obrazovky/{displayId}/duplikovat',
            'en' => '/en/display-editor/{displayId}/duplicate',
            'es' => '/es/display-editor/{displayId}/duplicate',
            'ja' => '/ja/display-editor/{displayId}/duplicate',
            'fr' => '/fr/display-editor/{displayId}/duplicate',
            'de' => '/de/display-editor/{displayId}/duplicate',
        ],
        name: 'duplicate_competition_display',
        requirements: ['displayId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $displayId): Response
    {
        $display = $this->getCompetitionDisplays->byId($displayId);
        $this->denyAccessUnlessGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $display->competitionId);

        if (!$this->isCsrfTokenValid(CompetitionDisplayActions::csrfTokenId($displayId), $request->request->getString('_token'))) {
            throw $this->createAccessDeniedException();
        }

        try {
            $this->messageBus->dispatch(new DuplicateCompetitionDisplay(
                sourceDisplayId: $displayId,
                newDisplayId: Uuid::uuid7(),
                competitionId: $display->competitionId,
                name: $this->translator->trans('competition_display.copy_name', ['%name%' => $display->name]),
                actorPlayerId: $this->retrieveLoggedUserProfile->getProfile()?->playerId,
            ));

            $this->addFlash('success', $this->translator->trans('competition_display.flash.duplicated', ['%name%' => $display->name]));
        } catch (CompetitionDisplayLimitReached) {
            $this->addFlash('danger', $this->translator->trans('competition_display.flash.limit_reached', [
                '%max%' => CompetitionDisplay::MAX_PER_COMPETITION,
            ]));
        }

        $returnUrl = ReturnUrl::tryFrom($request->request->getString('return'));

        return $this->redirect(
            $returnUrl->path ?? $this->generateUrl('competition_displays', ['competitionId' => $display->competitionId]),
            Response::HTTP_SEE_OTHER,
        );
    }
}
