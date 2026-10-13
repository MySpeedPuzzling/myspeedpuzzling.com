<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Controller\Compete;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use Psr\Log\LoggerInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Exceptions\OnlineParticipantNotEntered;
use SpeedPuzzling\Web\Exceptions\OnlineRoundNotOnline;
use SpeedPuzzling\Web\Message\RegisterOnlineMedia;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStorage;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineParticipantApi;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineStoreBreaker;
use SpeedPuzzling\Web\Value\OnlineEventInput;
use SpeedPuzzling\Web\Value\OnlineHeartbeat;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use SpeedPuzzling\Web\Value\OnlineMediaStore;
use Symfony\Bundle\FrameworkBundle\Controller\AbstractController;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\Messenger\MessageBusInterface;
use Symfony\Component\Messenger\Stamp\HandledStamp;
use Symfony\Component\Routing\Attribute\Route;

/**
 * A presigned upload for one evidence object (docs/features/online-competitions/README.md §5.11,
 * assets/online/media_outbox.js): `{deviceId, roundId?, kind, takenMs, bytes, contentType, eventId?, previousFailure?}`
 * → `{serverNowMs, mediaId, store, method: "PUT", url, headers, expiresAtMs}`. The phone PUTs the bytes straight to
 * the store, then sends `media.uploaded`.
 * - `kind` one of OnlineMediaKind (`media_kind_unknown` 422), `bytes` the exact size of the blob - at most MAX_BYTES
 *   (`media_too_large` 422) - and `contentType` `image/jpeg` for photos, `application/octet-stream` for a burst; the
 *   URL is signed for exactly those (`invalid_fields` 422 for anything else wrong).
 * - The round: the one named, else the participant's current one - a round of their competition with online timing
 *   (`round_not_online` 409) they are entered in (`not_entered` 409).
 * - `previousFailure` = the store's answer to this phone's last upload of the item: a 408, 429 or 5xx counts for the
 *   circuit breaker (OnlineStoreBreaker) before the store of this upload is picked.
 * The media id is made here, before the dispatch, so the row and the URL share it.
 */
final class ApiMediaController extends AbstractController
{
    public const int MAX_BYTES = 15 * 1024 * 1024;
    public const string PHOTO_CONTENT_TYPE = 'image/jpeg';
    public const string BURST_CONTENT_TYPE = 'application/octet-stream';

    public function __construct(
        private readonly OnlineParticipantApi $api,
        private readonly MessageBusInterface $messageBus,
        private readonly OnlineMediaStorage $onlineMediaStorage,
        private readonly OnlineStoreBreaker $onlineStoreBreaker,
        private readonly LoggerInterface $logger,
    ) {
    }

    #[Route(path: '/api/compete/{token}/media', name: 'compete_api_media', methods: ['POST'])]
    public function __invoke(Request $request, string $token): JsonResponse
    {
        $refusal = $this->api->admit($token);

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

        $kind = is_string($body['kind'] ?? null) ? OnlineMediaKind::tryFrom($body['kind']) : null;

        if ($kind === null) {
            return $this->api->error('media_kind_unknown', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $bytes = self::integer($body['bytes'] ?? null);

        if ($bytes !== null && $bytes > self::MAX_BYTES) {
            return $this->api->error('media_too_large', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        $deviceId = OnlineHeartbeat::deviceId($body['deviceId'] ?? null);
        $takenMs = self::integer($body['takenMs'] ?? null);
        $roundId = $body['roundId'] ?? $access->round?->id->toString();
        $relatedEventId = $body['eventId'] ?? $body['relatedEventId'] ?? null;
        $contentType = $kind === OnlineMediaKind::Burst ? self::BURST_CONTENT_TYPE : self::PHOTO_CONTENT_TYPE;

        if (
            $deviceId === null
            || $bytes === null
            || $bytes < 1
            || ($body['contentType'] ?? $contentType) !== $contentType
            || $takenMs === null
            || $takenMs < OnlineEventInput::MIN_CLIENT_MS
            || $takenMs > OnlineEventInput::MAX_CLIENT_MS
            || ($roundId !== null && (!is_string($roundId) || !Uuid::isValid($roundId)))
            || ($relatedEventId !== null && (!is_string($relatedEventId) || !Uuid::isValid($relatedEventId)))
        ) {
            return $this->api->error('invalid_fields', JsonResponse::HTTP_UNPROCESSABLE_ENTITY);
        }

        if ($roundId === null) {
            return $this->api->error('round_not_online', JsonResponse::HTTP_CONFLICT);
        }

        $this->recordPreviousFailure($body['previousFailure'] ?? null, $access->participant->id->toString());

        $mediaId = Uuid::uuid7()->toString();

        try {
            $envelope = $this->messageBus->dispatch(new RegisterOnlineMedia(
                mediaId: $mediaId,
                participantId: $access->participant->id->toString(),
                roundId: strtolower($roundId),
                deviceId: $deviceId,
                kind: $kind,
                takenMs: $takenMs,
                bytes: $bytes,
                contentType: $contentType,
                relatedEventId: $relatedEventId === null ? null : strtolower($relatedEventId),
            ));
        } catch (OnlineRoundNotOnline) {
            return $this->api->error('round_not_online', JsonResponse::HTTP_CONFLICT);
        } catch (OnlineParticipantNotEntered) {
            return $this->api->error('not_entered', JsonResponse::HTTP_CONFLICT);
        } catch (UniqueConstraintViolationException $e) {
            // The phone's first contact with the round, twice at once - the online entry was made by the other request
            $this->logger->info('Online entry created by another request at the same time - the phone asks again', [
                'participant_id' => $access->participant->id->toString(),
                'exception' => $e,
            ]);

            return $this->api->error('retry', JsonResponse::HTTP_SERVICE_UNAVAILABLE, ['Retry-After' => '1']);
        }

        $media = $envelope->last(HandledStamp::class)?->getResult();
        assert($media instanceof OnlineEntryMedia);

        $upload = $this->onlineMediaStorage->presignPut($media);

        return $this->api->json([
            'mediaId' => $media->id->toString(),
            'store' => $upload->store->value,
            'method' => 'PUT',
            'url' => $upload->url,
            'headers' => $upload->headers,
            'expiresAtMs' => $upload->expiresAtMs,
        ]);
    }

    /**
     * A whole number - JSON may carry one as `12.0`
     */
    private static function integer(mixed $value): null|int
    {
        if (is_int($value)) {
            return $value;
        }

        return is_float($value) && is_finite($value) && floor($value) === $value && abs($value) < 2 ** 53 ? (int) $value : null;
    }

    /**
     * The phone's last upload of this item failed at the store: `{status, store, mediaId}`
     */
    private function recordPreviousFailure(mixed $failure, string $participantId): void
    {
        if (!is_array($failure)) {
            return;
        }

        $status = $failure['status'] ?? null;
        $store = is_string($failure['store'] ?? null) ? OnlineMediaStore::tryFrom($failure['store']) : null;

        // 400 / 403: a URL the store refused (ran out, signature) - no sign of a store being down
        if ($store !== null && is_int($status) && ($status === 408 || $status === 429 || $status >= 500)) {
            $this->onlineStoreBreaker->recordFailure($store, $participantId);
        }
    }
}
