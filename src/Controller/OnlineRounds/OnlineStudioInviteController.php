<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\OnlineRounds;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Controller\FirstTry\FirstTryConflictsController;
use SpeedPuzzling\Web\Entity\StudioInvite;
use SpeedPuzzling\Web\Exceptions\CompetitionParticipantNotFound;
use SpeedPuzzling\Web\Exceptions\CompetitionRoundNotFound;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotFound;
use SpeedPuzzling\Web\Exceptions\StudioInviteRefused;
use SpeedPuzzling\Web\Message\InviteToStudio;
use SpeedPuzzling\Web\Results\StudioInviteState;
use SpeedPuzzling\Web\Services\LiveKit\LiveKitSettings;
use SpeedPuzzling\Web\Services\LiveKit\StudioLiveUpdates;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineOrganiserApi;
use SpeedPuzzling\Web\Value\CanonicalUuid;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

/**
 * "Invite to the studio" (docs/features/online-competitions/contracts/phase-1b.md §4, §5 `online_studio_invite`):
 * `{"participantId": "…"}` → `{invite: {id, participantId, status, invitedAtMs, expiresAtMs, onAirFromMs, onAirToMs}}`
 * (InviteToStudio). Once committed the participant's phone hears about it - a data message in the hall, and the
 * heartbeat's signal (StudioLiveUpdates::invited()).
 *
 * JSON on the official results API's rules for the round's control room (OnlineOrganiserApi: ONLINE_ROUNDS_MANAGE,
 * `Content-Type: application/json`, the stateless CSRF header). Refusals: `video_unavailable` 409 (LIVEKIT_* empty, or
 * the round's live video off), `participant_not_found` 404, `not_entered` 409, `already_invited` 409 (with `invite` -
 * the one open), `invalid_fields` 422.
 */
final class OnlineStudioInviteController extends AbstractController
{
    public function __construct(
        private readonly OnlineOrganiserApi $api,
        private readonly LiveKitSettings $liveKitSettings,
        private readonly MessageBusInterface $messageBus,
        private readonly StudioLiveUpdates $studioLiveUpdates,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(
        path: [
            'cs' => '/online-kola/{roundId}/studio/pozvanky',
            'en' => '/en/online-rounds/{roundId}/studio-invites',
            'es' => '/es/online-rounds/{roundId}/studio-invites',
            'ja' => '/ja/online-rounds/{roundId}/studio-invites',
            'fr' => '/fr/online-rounds/{roundId}/studio-invites',
            'de' => '/de/online-rounds/{roundId}/studio-invites',
        ],
        name: 'online_studio_invite',
        requirements: ['roundId' => FirstTryConflictsController::ID_REQUIREMENT],
        methods: ['POST'],
    )]
    public function __invoke(Request $request, string $roundId): JsonResponse
    {
        $access = $this->api->authorise($request, $roundId, review: false, write: true);

        if ($access instanceof JsonResponse) {
            return $access;
        }

        if ($this->liveKitSettings->isAvailable() === false || $access['onlineRound']->videoEnabled === false) {
            return OfficialResultsApi::error('video_unavailable', JsonResponse::HTTP_CONFLICT);
        }

        $body = OfficialResultsApi::body($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $participantId = $body['participantId'] ?? null;

        if (!CanonicalUuid::isValid($participantId)) {
            return OfficialResultsApi::error('invalid_fields', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $round = $access['round'];

        try {
            $envelope = $this->messageBus->dispatch(new InviteToStudio(
                competitionId: $round->competition->id->toString(),
                roundId: $round->id->toString(),
                participantId: strtolower($participantId),
                actingPlayerId: $access['playerId'],
            ));
        } catch (CompetitionParticipantNotFound) {
            return OfficialResultsApi::error('participant_not_found', JsonResponse::HTTP_NOT_FOUND);
        } catch (StudioInviteRefused $refused) {
            $details = $refused->invite === null ? [] : ['invite' => StudioInviteState::of($refused->invite, $this->clock->now())->toArray()];

            return OfficialResultsApi::error($refused->reason, JsonResponse::HTTP_CONFLICT, $details);
        } catch (CompetitionRoundNotFound | OnlineRoundNotFound) {
            // Deleted, or online timing turned off, since the authorisation read it
            return OfficialResultsApi::error('round_not_online', JsonResponse::HTTP_NOT_FOUND);
        }

        $invite = $envelope->last(HandledStamp::class)?->getResult();
        assert($invite instanceof StudioInvite);

        // Committed - the participant's phone hears about it
        $this->studioLiveUpdates->invited($invite);

        return OfficialResultsApi::json(['invite' => StudioInviteState::of($invite, $this->clock->now())->toArray()]);
    }
}
