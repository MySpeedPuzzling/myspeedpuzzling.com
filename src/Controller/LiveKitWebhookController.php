<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Exceptions\LiveKitWebhookRejected;
use SpeedPuzzling\Web\Message\RecordStudioGuestPresence;
use SpeedPuzzling\Web\Repository\StudioInviteRepository;
use SpeedPuzzling\Web\Services\LiveKit\LiveKitSettings;
use SpeedPuzzling\Web\Services\LiveKit\LiveKitWebhookVerifier;
use SpeedPuzzling\Web\Services\LiveKit\StudioLiveUpdates;
use SpeedPuzzling\Web\Services\OfficialResultsApi;
use SpeedPuzzling\Web\Value\LiveKitIdentity;
use SpeedPuzzling\Web\Value\LiveKitRoomKind;
use SpeedPuzzling\Web\Value\StudioInviteStatus;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

/**
 * LiveKit's webhooks (docs/features/online-competitions/contracts/phase-1b.md §5 `livekit_webhook`): server to server,
 * on the stateless firewall - no session, no CSRF; the signature is the access right (LiveKitWebhookVerifier: 401
 * `invalid_signature` otherwise). Fast and idempotent - LiveKit sends an event again when it got no answer.
 *
 * Phase 1B reads one thing from them: a participant's camera device (`p:{participantId}`) joining or leaving a studio
 * (`studio-{roundId}`) - RecordStudioGuestPresence keeps their invitation's time on air. A leave that is only a
 * reconnect (the same identity joined again over a new connection, a move to another server) is no leave. Every other
 * event, room and identity is acknowledged and ignored (200, logged at debug). `video_unavailable` 409 while LIVEKIT_*
 * is empty - nothing could be verified.
 *
 * The studio's safety (contract phase-1b.md §8): a studio token alone never puts anybody on air - anybody of a
 * participant's (`p:…`, their camera or a second screen) joining a studio without a joined invitation of theirs in
 * that round is removed at once (StudioLiveUpdates::removedFromStudio(), after the presence is committed, logged at
 * info): a token kept from an earlier invitation, a guest the host thanked coming back.
 */
final class LiveKitWebhookController extends AbstractController
{
    private const array PRESENCE_EVENTS = [
        'participant_joined' => true,
        'participant_left' => false,
    ];

    private const array RECONNECT_REASONS = ['DUPLICATE_IDENTITY', 'MIGRATION'];

    // An event's own time is trusted when it is this close to ours
    private const int MAX_CLOCK_DIFFERENCE_SECONDS = 3600;

    public function __construct(
        private readonly LiveKitSettings $liveKitSettings,
        private readonly LiveKitWebhookVerifier $liveKitWebhookVerifier,
        private readonly MessageBusInterface $messageBus,
        private readonly StudioInviteRepository $studioInviteRepository,
        private readonly StudioLiveUpdates $studioLiveUpdates,
        private readonly ClockInterface $clock,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/-/livekit-webhook', name: 'livekit_webhook', methods: ['POST'])]
    public function __invoke(Request $request): JsonResponse
    {
        if ($this->liveKitSettings->isAvailable() === false) {
            return OfficialResultsApi::error('video_unavailable', JsonResponse::HTTP_CONFLICT);
        }

        try {
            $event = $this->liveKitWebhookVerifier->verify($request->getContent(), $request->headers->get('Authorization'));
        } catch (LiveKitWebhookRejected $e) {
            $this->logger->info('A LiveKit webhook was rejected', ['exception' => $e]);

            return OfficialResultsApi::error('invalid_signature', JsonResponse::HTTP_UNAUTHORIZED);
        }

        $name = is_string($event['event'] ?? null) ? $event['event'] : '';
        $room = is_array($event['room'] ?? null) ? $event['room'] : [];
        $participant = is_array($event['participant'] ?? null) ? $event['participant'] : [];
        $roundId = LiveKitRoomKind::Studio->roundOf(is_string($room['name'] ?? null) ? $room['name'] : '');
        $identity = is_string($participant['identity'] ?? null) ? $participant['identity'] : '';
        $participantId = LiveKitIdentity::participantOf($identity);
        $present = self::PRESENCE_EVENTS[$name] ?? null;

        if ($present === null || $roundId === null || str_starts_with($identity, LiveKitIdentity::PARTICIPANT_PREFIX) === false) {
            $this->logger->debug('A LiveKit webhook was acknowledged and ignored', ['event' => $name, 'room' => $room['name'] ?? null]);

            return OfficialResultsApi::json(['received' => true]);
        }

        // A participant's second screen (or no device of ours): never on air - in the studio only beside a guest
        if ($participantId === null) {
            if ($present && $this->secondScreenMayWatch($roundId, $identity) === false) {
                $this->studioLiveUpdates->removedFromStudio($roundId, $identity);
            } else {
                $this->logger->debug('A participant\'s second screen in the studio was acknowledged', ['event' => $name, 'round_id' => $roundId]);
            }

            return OfficialResultsApi::json(['received' => true]);
        }

        if ($present === false && in_array($participant['disconnectReason'] ?? null, self::RECONNECT_REASONS, true)) {
            $this->logger->debug('A studio guest reconnected - still on air', ['round_id' => $roundId, 'participant_id' => $participantId]);

            return OfficialResultsApi::json(['received' => true]);
        }

        $onAir = $this->messageBus->dispatch(new RecordStudioGuestPresence(
            roundId: $roundId,
            participantId: $participantId,
            present: $present,
            at: $this->occurredAt($event['createdAt'] ?? null),
        ))->last(HandledStamp::class)?->getResult();

        // Committed - a studio token alone never puts anybody on air
        if ($present && $onAir !== true) {
            $this->studioLiveUpdates->removedFromStudio($roundId, $identity);
        }

        return OfficialResultsApi::json(['received' => true]);
    }

    /**
     * A second screen (`p:{participantId}:screen`) may watch the studio only while its participant is a guest there - a
     * joined invitation in the round; anything else of a participant's is never invited
     */
    private function secondScreenMayWatch(string $roundId, string $identity): bool
    {
        if (str_ends_with($identity, LiveKitIdentity::SCREEN_SUFFIX) === false) {
            return false;
        }

        $participantId = LiveKitIdentity::participantOf(substr($identity, 0, -strlen(LiveKitIdentity::SCREEN_SUFFIX)));

        if ($participantId === null) {
            return false;
        }

        return $this->studioInviteRepository->latestOf($roundId, $participantId)?->statusAt($this->clock->now()) === StudioInviteStatus::Joined;
    }

    /**
     * The event's own time (`createdAt`, Unix seconds - a string in the protocol's JSON), unless it is far from ours
     */
    private function occurredAt(mixed $createdAt): DateTimeImmutable
    {
        $now = $this->clock->now();

        if ((is_int($createdAt) || (is_string($createdAt) && ctype_digit($createdAt))) && abs((int) $createdAt - $now->getTimestamp()) <= self::MAX_CLOCK_DIFFERENCE_SECONDS) {
            return (new DateTimeImmutable('@' . (int) $createdAt))->setTimezone($now->getTimezone());
        }

        return $now;
    }
}
