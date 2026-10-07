<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Referees;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\FormData\AddCompetitionRefereesFormData;
use SpeedPuzzling\Web\FormType\AddCompetitionRefereesFormType;
use SpeedPuzzling\Web\Message\AddCompetitionReferee;
use SpeedPuzzling\Web\MessageHandler\AddCompetitionRefereeHandler;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Services\CompetitionRefereesPage;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\CompetitionRefereeAddition;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * The referees of a competition (docs/features/competitions-management/live-results.md "Referees"): players the
 * organisers let enter results in the live entry - nothing else. Organisers only; lists them, adds them by player
 * search, and shows the link (and its QR) to hand them.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class CompetitionRefereesController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRefereesPage $page,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/rozhodci-udalosti/{competitionId}',
            'en' => '/en/manage-event-referees/{competitionId}',
            'es' => '/es/manage-event-referees/{competitionId}',
            'ja' => '/ja/manage-event-referees/{competitionId}',
            'fr' => '/fr/manage-event-referees/{competitionId}',
            'de' => '/de/manage-event-referees/{competitionId}',
        ],
        name: 'competition_referees',
        requirements: ['competitionId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $competitionId): Response
    {
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);

        $data = new AddCompetitionRefereesFormData();
        $form = $this->createForm(AddCompetitionRefereesFormType::class, $data, [
            'action' => $this->generateUrl('competition_referees', ['competitionId' => $competitionId]),
        ]);
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $profile = $this->retrieveLoggedUserProfile->getProfile();
            assert($profile !== null);

            $outcomes = [];
            foreach ($data->players as $playerId) {
                $envelope = $this->messageBus->dispatch(new AddCompetitionReferee($competitionId, $playerId, $profile->playerId));
                $outcome = $envelope->last(HandledStamp::class)?->getResult();
                assert($outcome instanceof CompetitionRefereeAddition);
                $outcomes[] = $outcome;
            }

            $this->flashOutcomes($outcomes);

            return $this->redirectToRoute('competition_referees', ['competitionId' => $competitionId], Response::HTTP_SEE_OTHER);
        }

        $response = $this->render(CompetitionRefereesPage::TEMPLATE, [
            ...$this->page->parameters($competitionId, $request->getLocale()),
            'form' => $form,
        ]);
        self::privatePage($response);

        return $response;
    }

    public static function privatePage(Response $response): void
    {
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');
        $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
    }

    /**
     * @param list<CompetitionRefereeAddition> $outcomes
     */
    private function flashOutcomes(array $outcomes): void
    {
        $counts = array_count_values(array_map(static fn (CompetitionRefereeAddition $outcome): string => $outcome->value, $outcomes));

        foreach ($counts as $outcome => $count) {
            $this->addFlash(
                $outcome === CompetitionRefereeAddition::Added->value ? 'success' : 'warning',
                $this->translator->trans('competition_referees.outcome.' . $outcome, [
                    '%count%' => $count,
                    '%limit%' => AddCompetitionRefereeHandler::MAX_REFEREES,
                ]),
            );
        }
    }
}
