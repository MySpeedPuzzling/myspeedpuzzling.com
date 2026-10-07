<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Referees;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\FormData\AddCompetitionRefereesFormData;
use SpeedPuzzling\Web\FormType\AddCompetitionRefereesFormType;
use SpeedPuzzling\Web\Message\RemoveCompetitionReferee;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\CompetitionRefereesPage;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * Takes a referee's rights away (live-results.md "Referees"). Organisers only; what they entered stays theirs
 * ("entered by"). A form older than the session (CSRF) gets the page again with 422 - nothing removed.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class RemoveCompetitionRefereeController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRefereesPage $page,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/rozhodci-udalosti/{competitionId}/{playerId}/odebrat',
            'en' => '/en/manage-event-referees/{competitionId}/{playerId}/remove',
            'es' => '/es/manage-event-referees/{competitionId}/{playerId}/remove',
            'ja' => '/ja/manage-event-referees/{competitionId}/{playerId}/remove',
            'fr' => '/fr/manage-event-referees/{competitionId}/{playerId}/remove',
            'de' => '/de/manage-event-referees/{competitionId}/{playerId}/remove',
        ],
        name: 'competition_referee_remove',
        requirements: [
            'competitionId' => FirstTryConflictsController::ID_REQUIREMENT,
            'playerId' => FirstTryConflictsController::ID_REQUIREMENT,
        ],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $competitionId, string $playerId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        if ($this->isCsrfTokenValid(CompetitionRefereesPage::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
            $form = $this->createForm(AddCompetitionRefereesFormType::class, new AddCompetitionRefereesFormData(), [
                'action' => $this->generateUrl('competition_referees', ['competitionId' => $competitionId]),
            ]);

            $response = $this->render(CompetitionRefereesPage::TEMPLATE, [
                ...$this->page->parameters($competitionId, $request->getLocale()),
                'form' => $form,
                'error' => $this->translator->trans('competition_referees.remove.expired'),
            ], new Response(status: Response::HTTP_UNPROCESSABLE_ENTITY));
            CompetitionRefereesController::privatePage($response);

            return $response;
        }

        $this->messageBus->dispatch(new RemoveCompetitionReferee($competitionId, $playerId));

        $this->addFlash('success', $this->translator->trans('competition_referees.remove.done'));

        return $this->redirectToRoute('competition_referees', ['competitionId' => $competitionId], Response::HTTP_SEE_OTHER);
    }
}
