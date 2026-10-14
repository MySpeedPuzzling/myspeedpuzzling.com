<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OnlineRounds;

use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotFound;
use SpeedPuzzling\Web\Query\GetStudioInvites;
use SpeedPuzzling\Web\Repository\CompetitionRoundRepository;
use SpeedPuzzling\Web\Repository\OnlineRoundRepository;
use SpeedPuzzling\Web\Security\CompetitionEditVoter;
use SpeedPuzzling\Web\Security\OnlineRoundsVoter;
use SpeedPuzzling\Web\Services\LiveKit\LiveKitSettings;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineOrganiserApi;
use SpeedPuzzling\Web\Value\RoundClock;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The host's studio of an online round (docs/features/online-competitions/README.md §5.12 "The studio flow", contract
 * phase-1b.md §5 `online_studio`): the host joins the studio room (`studio-{roundId}`) with their camera and
 * microphone (a moderator may share a screen too), sees the guests on air - the finishers the console invited -, thanks
 * them ("Thank you" = `online_studio_end`), and sees how many watch (the room's hidden spectators,
 * assets/controllers/online_studio_controller.js). Without live video - no media server (LIVEKIT_* empty) or the
 * round's video off - the page says so, nothing more.
 *
 * Organisers of the event for whom online rounds are on (COMPETITION_EDIT + ONLINE_ROUNDS_MANAGE), rounds with online
 * timing only, like the control room. Never cached.
 *
 * Asked with `Accept: application/json` it answers the round's invitations (`{serverNowMs, invites}`, GetStudioInvites:
 * every participant's latest one, its status as of now) - read by the studio page and by the control room's console
 * while video is on. JSON on the official results API's rules (OnlineOrganiserApi); `video_unavailable` 409 without
 * live video.
 */
final class OnlineStudioController extends AbstractController
{
    public function __construct(
        private readonly CompetitionRoundRepository $competitionRoundRepository,
        private readonly OnlineRoundRepository $onlineRoundRepository,
        private readonly OnlineOrganiserApi $api,
        private readonly LiveKitSettings $liveKitSettings,
        private readonly GetStudioInvites $getStudioInvites,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/online-kola/{roundId}/studio',
            'en' => '/en/online-rounds/{roundId}/studio',
            'es' => '/es/online-rounds/{roundId}/studio',
            'ja' => '/ja/online-rounds/{roundId}/studio',
            'fr' => '/fr/online-rounds/{roundId}/studio',
            'de' => '/de/online-rounds/{roundId}/studio',
        ],
        name: 'online_studio',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['GET'],
    )]
    public function __invoke(Request $request, string $roundId): Response
    {
        if (OnlineOrganiserApi::wantsJson($request)) {
            return $this->invites($request, $roundId);
        }

        $this->denyAccessUnlessGranted('IS_AUTHENTICATED_REMEMBERED');

        $round = $this->competitionRoundRepository->get($roundId);
        $competitionId = $round->competition->id->toString();
        $this->denyAccessUnlessGranted(CompetitionEditVoter::COMPETITION_EDIT, $competitionId);
        $this->denyAccessUnlessGranted(OnlineRoundsVoter::ONLINE_ROUNDS_MANAGE, $competitionId);

        $onlineRound = $this->onlineRoundRepository->ofRound($round->id->toString()) ?? throw new OnlineRoundNotFound();

        $response = $this->render('online_rounds/studio.html.twig', [
            'competition' => $round->competition,
            'round' => $round,
            'online_round' => $onlineRound,
            // Why there is no studio: no media server, or the round's live video is off - null: there is one
            'video_missing' => match (true) {
                $this->liveKitSettings->isAvailable() === false => 'unavailable',
                $onlineRound->videoEnabled === false => 'off',
                default => null,
            },
            // The placeholder of the end address - the page puts the invitation's id in its place
            'invite_placeholder' => Uuid::NIL,
        ]);
        $response->headers->set('Cache-Control', 'private, no-store');

        return $response;
    }

    private function invites(Request $request, string $roundId): JsonResponse
    {
        $access = $this->api->authorise($request, $roundId, review: false);

        if ($access instanceof JsonResponse) {
            return $access;
        }

        if ($this->liveKitSettings->isAvailable() === false || $access['onlineRound']->videoEnabled === false) {
            return OfficialResultsApi::error('video_unavailable', JsonResponse::HTTP_CONFLICT);
        }

        $now = $this->clock->now();

        return OfficialResultsApi::json([
            'serverNowMs' => RoundClock::milliseconds($now),
            'invites' => $this->getStudioInvites->latestOfRound($access['round']->id->toString(), $now),
        ]);
    }
}
