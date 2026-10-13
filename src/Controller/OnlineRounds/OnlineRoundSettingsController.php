<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OnlineRounds;

use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\OnlineTimingNotAvailable;
use SpeedPuzzling\Web\FormData\OnlineRoundSettingsFormData;
use SpeedPuzzling\Web\FormType\OnlineRoundSettingsFormType;
use SpeedPuzzling\Web\Message\EnableOnlineRound;
use SpeedPuzzling\Web\Message\UpdateOnlineRoundSettings;
use SpeedPuzzling\Web\Query\GetOnlineRoundSetup;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Security\OnlineRoundsVoter;
use SpeedPuzzling\Web\Services\LiveKit\LiveKitSettings;
use SpeedPuzzling\Web\Services\RetrieveLoggedUserProfile;
use SpeedPuzzling\Web\Value\OnlineTimingAvailability;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;
use Symfony\Component\Security\Http\Attribute\IsGranted;
use Symfony\Contracts\Translation\TranslatorInterface;

/**
 * "Online timing" of a round (docs/features/online-competitions/, contract §5 `online_round_settings`): whether the
 * round uses it, the button turning it on (EnableOnlineRound - also gives the participants their personal links), the
 * settings (UpdateOnlineRoundSettings) and how many of the event's participants are entered in the round. Organisers
 * for whom the feature is on (ONLINE_ROUNDS_MANAGE). The live video switches (contract phase-1b.md §2) are in the form
 * only while a media server is configured (LiveKitSettings); hidden, they keep their values.
 *
 * Every POST either redirects (done - back to this page) or answers 422 (an invalid form, a refusal, an expired page):
 * Turbo Drive drops a 200 answer to a form. Never cached - the page leads to the personal links.
 */
#[IsGranted('IS_AUTHENTICATED_REMEMBERED')]
final class OnlineRoundSettingsController extends AbstractController
{
    public const string ENABLE_FIELD = 'enable_online_timing';

    // The forms' own stateless token id (config/packages/csrf.php): the "Turn on" button is a plain form
    public const string CSRF_TOKEN_ID = 'submit';

    public function __construct(
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly OnlineRoundRepository $onlineRoundRepository,
        private readonly GetOnlineRoundSetup $getOnlineRoundSetup,
        private readonly RetrieveLoggedUserProfile $retrieveLoggedUserProfile,
        private readonly MessageBusInterface $messageBus,
        private readonly TranslatorInterface $translator,
        private readonly LiveKitSettings $liveKitSettings,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/online-kola/{roundId}/nastaveni',
            'en' => '/en/online-rounds/{roundId}/settings',
            'es' => '/es/online-rounds/{roundId}/settings',
            'ja' => '/ja/online-rounds/{roundId}/settings',
            'fr' => '/fr/online-rounds/{roundId}/settings',
            'de' => '/de/online-rounds/{roundId}/settings',
        ],
        name: 'online_round_settings',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET', 'POST'],
    )]
    public function __invoke(Request $request, string $roundId): Response
    {
        $round = $this->competitionRoundRepository->get($roundId);
        $competition = $round->competition;
        $competitionId = $competition->id->toString();
        $this->denyAccessUnlessGranted(OnlineRoundsVoter::ONLINE_ROUNDS_MANAGE, $competitionId);

        $roundId = $round->id->toString();
        $availability = OnlineTimingAvailability::ofRound($round);
        $onlineRound = $this->onlineRoundRepository->ofRound($roundId);
        // The organiser's way back survives the round trips of this page
        $back = array_filter([
            'return' => $request->query->getString('return'),
            'return_title' => $request->query->getString('return_title'),
        ], static fn (string $value): bool => $value !== '');
        $pageUrl = $this->generateUrl('online_round_settings', ['roundId' => $roundId] + $back);
        $enableError = null;

        if ($request->isMethod('POST') && $request->request->has(self::ENABLE_FIELD)) {
            if ($this->isCsrfTokenValid(self::CSRF_TOKEN_ID, $request->request->getString('_token')) === false) {
                $enableError = 'online_rounds.settings.flash.expired';
            } elseif ($onlineRound !== null) {
                // Already on - another tab, a second click: nothing to do
                return $this->redirect($pageUrl, Response::HTTP_SEE_OTHER);
            } else {
                $profile = $this->retrieveLoggedUserProfile->getProfile();
                assert($profile !== null);

                try {
                    $envelope = $this->messageBus->dispatch(new EnableOnlineRound(
                        competitionId: $competitionId,
                        roundId: $roundId,
                        actingPlayerId: $profile->playerId,
                    ));
                    $linksGiven = $envelope->last(HandledStamp::class)?->getResult();

                    $this->addFlash('success', $this->translator->trans('online_rounds.settings.flash.enabled', [
                        '%round%' => $round->name,
                        '%count%' => is_int($linksGiven) ? $linksGiven : 0,
                    ]));

                    return $this->redirect($pageUrl, Response::HTTP_SEE_OTHER);
                } catch (OnlineTimingNotAvailable $refused) {
                    $enableError = 'online_rounds.settings.unavailable.' . $refused->availability->value;
                }
            }
        }

        $form = null;
        // The live video switches only while a media server is configured - without one they keep their values
        $videoAvailable = $this->liveKitSettings->isAvailable();

        if ($onlineRound !== null) {
            $form = $this->createForm(OnlineRoundSettingsFormType::class, OnlineRoundSettingsFormData::fromOnlineRound($onlineRound), [
                'action' => $pageUrl,
                'video_available' => $videoAvailable,
            ]);
            $form->handleRequest($request);

            if ($form->isSubmitted() && $form->isValid()) {
                $data = $form->getData();
                assert($data->detectionMode !== null && $data->unfinishedMeasure !== null && $data->missingPieceRule !== null);

                $this->messageBus->dispatch(new UpdateOnlineRoundSettings(
                    roundId: $roundId,
                    detectionMode: $data->detectionMode,
                    videoEnabled: $videoAvailable ? $data->videoEnabled : $onlineRound->videoEnabled,
                    participantsSeeEachOther: $videoAvailable ? $data->participantsSeeEachOther : $onlineRound->participantsSeeEachOther,
                    // Not in the form - kept as they are
                    studioEnabled: $onlineRound->studioEnabled,
                    recordingEnabled: $onlineRound->recordingEnabled,
                    unfinishedMeasure: $data->unfinishedMeasure,
                    missingPieceRule: $data->missingPieceRule,
                ));

                $this->addFlash('success', $this->translator->trans('online_rounds.settings.flash.saved', ['%round%' => $round->name]));

                return $this->redirect($pageUrl, Response::HTTP_SEE_OTHER);
            }
        }

        $response = $this->render('online_rounds/settings.html.twig', [
            'competition' => $competition,
            'round' => $round,
            'online_round' => $onlineRound,
            'availability' => $availability,
            'setup' => $this->getOnlineRoundSetup->ofRound($competitionId, $roundId),
            'form' => $form,
            'enable_error' => $enableError,
            'page_url' => $pageUrl,
            'csrf_token_id' => self::CSRF_TOKEN_ID,
            'enable_field' => self::ENABLE_FIELD,
        ]);

        // A POST that changed nothing (an expired page, a refusal, a form for a round without online timing) - 422 like
        // an invalid form (render() sets that one itself)
        if ($request->isMethod('POST') && $response->getStatusCode() === Response::HTTP_OK) {
            $response->setStatusCode(Response::HTTP_UNPROCESSABLE_ENTITY);
        }

        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }
}
