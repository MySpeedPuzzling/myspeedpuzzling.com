<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Entity\CompetitionDisplay;
use SpeedPuzzling\Web\Exceptions\CompetitionDisplayLimitReached;
use SpeedPuzzling\Web\Message\AddCompetitionDisplay;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplaysPage;
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
 * "New display" (D-displays-overlays.md §8.1): added = straight into the editor of the new display (303), the event at
 * its limit = back to where the form was (?return=, the hub or the displays page), refused = the displays page with the
 * form's errors and 422 (Turbo drops a 200 answer to a full-page POST).
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class AddCompetitionDisplayController extends AbstractController
{
    public function __construct(
        private readonly CompetitionDisplaysPage $page,
        private readonly MessageBusInterface $messageBus,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/sprava-obrazovek/{competitionId}/pridat',
            'en' => '/en/manage-displays/{competitionId}/add',
            'es' => '/es/manage-displays/{competitionId}/add',
            'ja' => '/ja/manage-displays/{competitionId}/add',
            'fr' => '/fr/manage-displays/{competitionId}/add',
            'de' => '/de/manage-displays/{competitionId}/add',
        ],
        name: 'add_competition_display',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $competitionId);

        $returnPath = ReturnUrl::tryFrom($request->query->getString('return'))->path
            ?? $this->generateUrl('competition_displays', ['competitionId' => $competitionId]);

        $form = $this->page->addForm($request, $competitionId, $returnPath);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            assert($data->kind !== null && $data->preset !== null && $data->name !== null && $data->locale !== null);

            $displayId = Uuid::uuid7();

            try {
                $this->messageBus->dispatch(new AddCompetitionDisplay(
                    displayId: $displayId,
                    competitionId: $competitionId,
                    kind: $data->kind,
                    name: $data->name,
                    locale: $data->locale,
                    actorPlayerId: $this->retrieveLoggedUserProfile->getProfile()?->playerId,
                    presetKey: $data->preset,
                ));
            } catch (CompetitionDisplayLimitReached) {
                // The event has the most displays it can have (another add may have taken the last place meanwhile)
                $this->addFlash('danger', $this->translator->trans('competition_display.flash.limit_reached', [
                    '%max%' => CompetitionDisplay::MAX_PER_COMPETITION,
                ]));

                return $this->redirect($returnPath, Response::HTTP_SEE_OTHER);
            }

            $this->addFlash('success', $this->translator->trans('competition_display.flash.added'));

            return $this->redirectToRoute('edit_competition_display', ['displayId' => $displayId->toString()], Response::HTTP_SEE_OTHER);
        }

        // Not submitted (an empty POST) or refused: the displays page with the errors, 422
        $response = $this->render(
            'competition_toolkit/displays.html.twig',
            $this->page->variables($request, $competitionId, $form),
            new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY),
        );
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
