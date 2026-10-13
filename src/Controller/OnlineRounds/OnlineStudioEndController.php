<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OnlineRounds;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Exceptions\StudioInviteNotFound;
use SpeedPuzzling\Web\Message\EndStudioGuest;
use SpeedPuzzling\Web\Repository\StudioInviteRepository;
use SpeedPuzzling\Web\Results\EndedStudioGuest;
use SpeedPuzzling\Web\Results\StudioInviteState;
use SpeedPuzzling\Web\Services\LiveKit\LiveKitSettings;
use SpeedPuzzling\Web\Services\LiveKit\StudioLiveUpdates;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineOrganiserApi;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The host's "Thank you" (docs/features/online-competitions/contracts/phase-1b.md §4, §5 `online_studio_end`): the
 * invitation ends (EndStudioGuest) → `{invite: {…, status}}`; once committed a guest who was on air loses their
 * publishing in the studio and the heartbeat's signal goes (StudioLiveUpdates::ended()). Ending one that is over
 * already answers it as it is.
 *
 * JSON on the official results API's rules for the round's control room (OnlineOrganiserApi: ONLINE_ROUNDS_MANAGE,
 * `Content-Type: application/json`, the stateless CSRF header); `invite_not_found` 404 for an invitation of another
 * round; `video_unavailable` 409 only while LIVEKIT_* is empty - the round's video turned off meanwhile never keeps a
 * host from ending an invitation.
 */
final class OnlineStudioEndController extends AbstractController
{
    public function __construct(
        private readonly OnlineOrganiserApi $api,
        private readonly LiveKitSettings $liveKitSettings,
        private readonly StudioInviteRepository $studioInviteRepository,
        private readonly MessageBusInterface $messageBus,
        private readonly StudioLiveUpdates $studioLiveUpdates,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/online-kola/{roundId}/studio/pozvanky/{inviteId}/ukoncit',
            'en' => '/en/online-rounds/{roundId}/studio-invites/{inviteId}/end',
            'es' => '/es/online-rounds/{roundId}/studio-invites/{inviteId}/end',
            'ja' => '/ja/online-rounds/{roundId}/studio-invites/{inviteId}/end',
            'fr' => '/fr/online-rounds/{roundId}/studio-invites/{inviteId}/end',
            'de' => '/de/online-rounds/{roundId}/studio-invites/{inviteId}/end',
        ],
        name: 'online_studio_end',
        requirements: [
            'roundId' => FirstTryConflictsController::ID_REQUIREMENT,
            'inviteId' => FirstTryConflictsController::ID_REQUIREMENT,
        ],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $roundId, string $inviteId): JsonResponse
    {
        $access = $this->api->authorise($request, $roundId, review: false, write: true);

        if ($access instanceof JsonResponse) {
            return $access;
        }

        if ($this->liveKitSettings->isAvailable() === false) {
            return OfficialResultsApi::error('video_unavailable', JsonResponse::HTTP_CONFLICT);
        }

        try {
            // The host was authorised for this round only
            if ($this->studioInviteRepository->get($inviteId)->round->id->toString() !== $access['round']->id->toString()) {
                throw new StudioInviteNotFound();
            }

            $envelope = $this->messageBus->dispatch(new EndStudioGuest(
                inviteId: strtolower($inviteId),
                actingPlayerId: $access['playerId'],
            ));
        } catch (StudioInviteNotFound) {
            return OfficialResultsApi::error('invite_not_found', JsonResponse::HTTP_NOT_FOUND);
        }

        $ended = $envelope->last(HandledStamp::class)?->getResult();
        assert($ended instanceof EndedStudioGuest);

        // Committed - the guest goes off air
        $this->studioLiveUpdates->ended($ended->invite, $ended->wasOnAir);

        return OfficialResultsApi::json(['invite' => StudioInviteState::of($ended->invite, $this->clock->now())->toArray()]);
    }
}
