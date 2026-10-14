<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Compete;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\StudioInvite;
use SpeedPuzzling\Web\Exceptions\StudioInviteNotFound;
use SpeedPuzzling\Web\Exceptions\StudioInviteRefused;
use SpeedPuzzling\Web\Message\AnswerStudioInvite;
use SpeedPuzzling\Web\Services\LiveKit\LiveKitSettings;
use SpeedPuzzling\Web\Services\LiveKit\StudioLiveUpdates;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineParticipantApi;
use SpeedPuzzling\Web\Value\CanonicalUuid;
use SpeedPuzzling\Web\Value\StudioInviteAnswer;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

/**
 * The participant's answer to an invitation to the studio (contract phase-1b.md §4, §5): `{"answer": "join" |
 * "decline"}` → `{serverNowMs, studio: {inviteId, status}}` (AnswerStudioInvite) - after "join" the phone asks for its
 * studio token (`compete_api_video_token` with `room: "studio"`). The personal link is the access right
 * (OnlineParticipantApi); refusals: `video_unavailable` 409 (no media server, or the round's video turned off before
 * a join), `invite_not_found` 404 (unknown, or another participant's), `invite_not_open` / `invite_expired` 409,
 * `invalid_fields` 422. Once committed, the heartbeat's signal of the invitation goes (StudioLiveUpdates::answered()).
 */
final class ApiStudioAnswerController extends AbstractController
{
    public function __construct(
        private readonly OnlineParticipantApi $api,
        private readonly LiveKitSettings $liveKitSettings,
        private readonly MessageBusInterface $messageBus,
        private readonly StudioLiveUpdates $studioLiveUpdates,
        private readonly ClockInterface $clock,
    ) {
    }

    #[Route(path: '/api/compete/{token}/studio-invites/{inviteId}', name: 'compete_api_studio_answer', methods: ['POST'])]
    public function __invoke(Request $request, string $token, string $inviteId): JsonResponse
    {
        $refusal = $this->api->admit($request, $token);

        if ($refusal !== null) {
            return $refusal;
        }

        if ($this->liveKitSettings->isAvailable() === false) {
            return $this->api->error('video_unavailable', JsonResponse::HTTP_CONFLICT);
        }

        $access = $this->api->access($token);

        if ($access instanceof JsonResponse) {
            return $access;
        }

        $body = $this->api->body($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $answer = is_string($body['answer'] ?? null) ? StudioInviteAnswer::tryFrom($body['answer']) : null;

        if ($answer === null) {
            return $this->api->error('invalid_fields', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (CanonicalUuid::isValid($inviteId) === false) {
            return $this->api->error('invite_not_found', JsonResponse::HTTP_NOT_FOUND);
        }

        try {
            $envelope = $this->messageBus->dispatch(new AnswerStudioInvite(
                inviteId: strtolower($inviteId),
                participantId: $access->participant->id->toString(),
                answer: $answer,
            ));
        } catch (StudioInviteNotFound) {
            return $this->api->error('invite_not_found', JsonResponse::HTTP_NOT_FOUND);
        } catch (StudioInviteRefused $refused) {
            return $this->api->error($refused->reason, JsonResponse::HTTP_CONFLICT);
        }

        $invite = $envelope->last(HandledStamp::class)?->getResult();
        assert($invite instanceof StudioInvite);

        $this->studioLiveUpdates->answered($invite);

        return $this->api->json([
            'studio' => [
                'inviteId' => $invite->id->toString(),
                'status' => $invite->statusAt($this->clock->now())->value,
            ],
        ]);
    }
}
