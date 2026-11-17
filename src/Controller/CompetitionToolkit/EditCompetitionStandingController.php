<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\InvalidCompetitionStanding;
use SpeedPuzzling\Web\FormData\CompetitionStandingFormData;
use SpeedPuzzling\Web\Message\EditCompetitionStanding;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionStandings;
use SpeedPuzzling\Web\Query\GetStandingResults;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionStandingForms;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\Form\FormError;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * A standing's settings with its live preview (competition toolkit A6). The voter is checked on the event of the
 * standing loaded from the database, never on an id from the request alone.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class EditCompetitionStandingController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly GetCompetitionStandings $getCompetitionStandings,
        private readonly GetStandingResults $getStandingResults,
        private readonly CompetitionStandingForms $forms,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/poradi-udalosti/{competitionId}/{standingId}/upravit',
            'en' => '/en/manage-event-standings/{competitionId}/{standingId}/edit',
            'es' => '/es/manage-event-standings/{competitionId}/{standingId}/edit',
            'ja' => '/ja/manage-event-standings/{competitionId}/{standingId}/edit',
            'fr' => '/fr/manage-event-standings/{competitionId}/{standingId}/edit',
            'de' => '/de/manage-event-standings/{competitionId}/{standingId}/edit',
        ],
        name: 'edit_competition_standing',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT, 'standingId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $competitionId, string $standingId): Response
    {
        $standing = $this->getCompetitionStandings->byId($standingId);

        if ($standing->competitionId !== strtolower($competitionId)) {
            throw $this->createNotFoundException();
        }

        $this->denyAccessUnlessGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $standing->competitionId);

        $form = $this->forms->create($standing->competitionId, CompetitionStandingFormData::fromStanding($standing), $request->getRequestUri());
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            assert($data->name !== null && $data->category !== null);

            try {
                $this->messageBus->dispatch(new EditCompetitionStanding(
                    standingId: $standing->id,
                    competitionId: $standing->competitionId,
                    name: $data->name,
                    category: $data->category,
                    roundIds: $data->roundIds,
                    rules: $data->rules(),
                ));

                $this->addFlash('success', $this->translator->trans('competition_standing.flash.saved'));

                return $this->redirectToRoute('competition_standings_manage', ['competitionId' => $standing->competitionId], Response::HTTP_SEE_OTHER);
            } catch (InvalidCompetitionStanding $refused) {
                $form->addError(new FormError($this->translator->trans($refused->translationKey())));
            }
        }

        $response = $this->render('competition_toolkit/standing_form.html.twig', [
            'competition' => $this->getCompetitionEvents->byId($standing->competitionId),
            'form' => $form,
            'standing' => $standing,
            'table' => $this->getStandingResults->organiserView($standing),
        ]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
