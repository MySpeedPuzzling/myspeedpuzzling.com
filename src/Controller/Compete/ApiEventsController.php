<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Compete;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use SpeedPuzzling\Web\Message\RecordOnlineEntryEvents;
use SpeedPuzzling\Web\Results\RecordedOnlineEntryEvents;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineAccessLight;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineParticipantApi;
use SpeedPuzzling\Web\Value\OnlineHeartbeat;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A participant's phone's events, in batches of up to MAX_EVENTS from its outbox (assets/online/event_outbox.js):
 * `{"deviceId": "…", "events": [{id, type, roundId, clientMs, perfMs, offsetMs, rttMs, data}, …]}` →
 * `{serverNowMs, acks, refused}` (RecordOnlineEntryEventsHandler). A refused event is no HTTP error - it is in
 * `refused` with its reason; the request itself is refused only when it can not be read (`invalid_json`,
 * `invalid_fields`, `too_many_events`) or the competition has no round with online timing (`round_not_online`).
 * Events naming no round belong to the participant's current one.
 *
 * Two requests storing the same new event at once (a phone sending again while its first request still runs): the
 * later commit fails on the primary key - answered 503 `retry` with `Retry-After`, the phone sends again and hears the
 * ack. A `device.ready` handing the entry to another device clears the heartbeat's cached lookup once the dispatch
 * returned (committed - OnlineAccessLight::forget(), a new generation: a heartbeat that read the link before the claim
 * and writes it to the cache after this is read again), so the old device learns it was taken over on its next beat and
 * the new one is never told to give way.
 */
final class ApiEventsController extends AbstractController
{
    public const int MAX_EVENTS = 50;

    public function __construct(
        private readonly OnlineParticipantApi $api,
        private readonly OnlineAccessLight $onlineAccessLight,
        private readonly MessageBusInterface $messageBus,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/api/compete/{token}/events', name: 'compete_api_events', methods: ['POST'])]
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $refusal = $this->api->admit($request, $token) ?? $this->api->admitEvents($token);

        if ($refusal !== null) {
            return $refusal;
        }

        $access = $this->api->access($token);

        if ($access instanceof JsonResponse) {
            return $access;
        }

        $body = $this->api->body($request);

        if ($body instanceof JsonResponse) {
            return $body;
        }

        $deviceId = OnlineHeartbeat::deviceId($body['deviceId'] ?? null);
        $events = $body['events'] ?? null;

        if ($deviceId === null || !is_array($events) || !array_is_list($events)) {
            return $this->api->error('invalid_fields', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if (count($events) > self::MAX_EVENTS) {
            return $this->api->error('too_many_events', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($access->round === null) {
            return $this->api->error('round_not_online', JsonResponse::HTTP_CONFLICT);
        }

        if ($events === []) {
            return $this->api->json(['acks' => [], 'refused' => []]);
        }

        try {
            $envelope = $this->messageBus->dispatch(new RecordOnlineEntryEvents(
                participantId: $access->participant->id->toString(),
                roundId: $access->round->id->toString(),
                deviceId: $deviceId,
                events: array_map(self::event(...), $events),
            ));
        } catch (UniqueConstraintViolationException $e) {
            $this->logger->info('Online events stored by another request at the same time - the phone sends them again', [
                'participant_id' => $access->participant->id->toString(),
                'exception' => $e,
            ]);

            return $this->api->error('retry', JsonResponse::HTTP_SERVICE_UNAVAILABLE, ['Retry-After' => '1']);
        }

        $recorded = $envelope->last(HandledStamp::class)?->getResult();
        assert($recorded instanceof RecordedOnlineEntryEvents);

        if ($recorded->activeDeviceChanged) {
            $this->onlineAccessLight->forget($token);
        }

        return $this->api->json([
            'acks' => $recorded->acks,
            'refused' => $recorded->refused,
        ]);
    }

    /**
     * An event as a JSON object - anything else is passed on empty, and refused as `invalid_fields` like any event
     * without an id
     *
     * @return array<string, mixed>
     */
    private static function event(mixed $event): array
    {
        if (!is_array($event) || array_is_list($event)) {
            return [];
        }

        /** @var array<string, mixed> $event */
        return $event;
    }
}
