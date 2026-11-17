<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\CompetitionToolkit;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\FormData\CompetitionDisplaySettingsFormData;
use SpeedPuzzling\Web\FormType\CompetitionDisplaySettingsFormType;
use SpeedPuzzling\Web\Message\ChangeCompetitionDisplayFollow;
use SpeedPuzzling\Web\Message\ChangeCompetitionDisplayLocale;
use SpeedPuzzling\Web\Message\RenameCompetitionDisplay;
use SpeedPuzzling\Web\Query\GetCompetitionDisplays;
use SpeedPuzzling\Web\Query\GetCompetitionEvents;
use SpeedPuzzling\Web\Query\GetCompetitionRoundsForManagement;
use SpeedPuzzling\Web\Security\CompetitionToolkitVoter;
use SpeedPuzzling\Web\Value\DisplayFollowMode;
use SpeedPuzzling\Web\Value\ReturnUrl;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;
use SpeedPuzzling\Web\Services\CompetitionToolkit\CompetitionDisplayLiveUpdates;

/**
 * A display's name, language and the round it follows (D-displays-overlays.md §8.1 `competition_display_settings`):
 * saved = back to ?return= (else the displays page), refused = 422. The voter is checked on the event of the display
 * loaded from the database, never on an id from the request.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class CompetitionDisplaySettingsController extends AbstractController
{
    public function __construct(
        private readonly GetCompetitionDisplays $getCompetitionDisplays,
        private readonly GetCompetitionEvents $getCompetitionEvents,
        private readonly GetCompetitionRoundsForManagement $getCompetitionRoundsForManagement,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly CompetitionDisplayLiveUpdates $displayLiveUpdates,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/editor-obrazovky/{displayId}/nastaveni',
            'en' => '/en/display-editor/{displayId}/settings',
            'es' => '/es/display-editor/{displayId}/settings',
            'ja' => '/ja/display-editor/{displayId}/settings',
            'fr' => '/fr/display-editor/{displayId}/settings',
            'de' => '/de/display-editor/{displayId}/settings',
        ],
        name: 'competition_display_settings',
        requirements: ['displayId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $displayId): Response
    {
        $display = $this->getCompetitionDisplays->byId($displayId);
        $this->denyAccessUnlessGranted(CompetitionToolkitVoter::COMPETITION_TOOLKIT_MANAGE, $display->competitionId);

        $rounds = $this->getCompetitionRoundsForManagement->ofCompetition($display->competitionId);
        $roundChoices = [];

        foreach ($rounds as $round) {
            $roundChoices[$round->id] = $round->name;
        }

        $form = $this->createForm(
            CompetitionDisplaySettingsFormType::class,
            CompetitionDisplaySettingsFormData::fromDisplay($display),
            [
                'action' => $request->getRequestUri(),
                'rounds' => $roundChoices,
            ],
        );
        $form->handleRequest($request);

        if ($form->isSubmitted() && $form->isValid()) {
            $data = $form->getData();
            assert($data->name !== null && $data->locale !== null && $data->followMode !== null);

            $this->messageBus->dispatch(new RenameCompetitionDisplay($displayId, $data->name));
            $this->messageBus->dispatch(new ChangeCompetitionDisplayLocale($displayId, $data->locale));
            $this->messageBus->dispatch(new ChangeCompetitionDisplayFollow(
                displayId: $displayId,
                mode: $data->followMode,
                pinnedRoundId: $data->followMode === DisplayFollowMode::Pinned ? $data->pinnedRoundId : null,
                roundIds: $data->followMode === DisplayFollowMode::Current ? $data->roundIds : [],
            ));

            // After the commit: the open display follows its new round(s) and language at once (competition toolkit D5)
            $this->displayLiveUpdates->displayChanged($display->competitionId, $displayId);

            $this->addFlash('success', $this->translator->trans('competition_display.flash.saved'));

            $returnUrl = ReturnUrl::tryFrom($request->query->getString('return'));

            return $this->redirect(
                $returnUrl->path ?? $this->generateUrl('competition_displays', ['competitionId' => $display->competitionId]),
                Response::HTTP_SEE_OTHER,
            );
        }

        // A refused form answers 422 (render() sets it when handed the form itself)
        $response = $this->render('competition_toolkit/display_settings.html.twig', [
            'competition' => $this->getCompetitionEvents->byId($display->competitionId),
            'display' => $display,
            'rounds' => $rounds,
            'form' => $form,
        ]);
        $response->setPrivate();
        $response->headers->addCacheControlDirective('no-store');

        return $response;
    }
}
