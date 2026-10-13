<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use AsyncAws\S3\Exception\NoSuchKeyException;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Value\OnlineMediaObject;
use SpeedPuzzling\Web\Value\OnlineMediaObjectList;
use SpeedPuzzling\Web\Value\OnlineMediaStore;
use SpeedPuzzling\Web\Value\PresignedUpload;
use SpeedPuzzling\Web\Value\RoundClock;

/**
 * Evidence in S3-compatible object storage (docs/features/online-competitions/README.md §5.11) - the media's store
 * (OnlineMediaStores) and its object key, as RegisterOnlineMediaHandler recorded them.
 * - presignPut(): a URL valid for PUT_LIFETIME_SECONDS, signed for the declared `Content-Type` and the exact
 *   `Content-Length` (the phone declares its blob's size; a browser sets the length from the blob) - the store refuses
 *   any other upload. Made for the store's public endpoint, without a request.
 * - presignGet(): a short-lived URL for a look (control room thumbnails, the review).
 * - head() / delete(): the server's own requests, through the store's server-side client.
 */
final readonly class S3OnlineMediaStorage implements OnlineMediaStorage
{
    // Long enough for a slow upload to start, short enough that a URL leaking from a phone is soon worthless
    public const int PUT_LIFETIME_SECONDS = 300;

    public function __construct(
        private OnlineMediaStores $stores,
        private S3QueryPresigner $presigner,
        private ClockInterface $clock,
    ) {
    }

    public function presignPut(OnlineEntryMedia $media): PresignedUpload
    {
        $now = $this->clock->now();
        $headers = [
            'Content-Type' => $media->contentType,
            'Content-Length' => (string) $media->declaredBytes,
        ];

        return new PresignedUpload(
            url: $this->presigner->presign(
                $this->stores->publicLocation($media->store),
                'PUT',
                $media->objectKey,
                $headers,
                $now,
                self::PUT_LIFETIME_SECONDS,
            ),
            headers: $headers,
            expiresAtMs: RoundClock::milliseconds($now) + self::PUT_LIFETIME_SECONDS * 1000,
            store: $media->store,
        );
    }

    public function presignGet(OnlineEntryMedia $media, int $ttlSeconds): string
    {
        return $this->presigner->presign(
            $this->stores->publicLocation($media->store),
            'GET',
            $media->objectKey,
            [],
            $this->clock->now(),
            $ttlSeconds,
        );
    }

    public function head(OnlineEntryMedia $media): null|int
    {
        return $this->inspect($media)?->bytes;
    }

    public function inspect(OnlineEntryMedia $media): null|OnlineMediaObject
    {
        $result = $this->stores->client($media->store)->headObject([
            'Bucket' => $this->stores->bucket($media->store),
            'Key' => $media->objectKey,
        ]);

        try {
            $result->resolve();
        } catch (NoSuchKeyException) {
            return null;
        }

        $bytes = $result->getContentLength();

        if ($bytes === null) {
            return null;
        }

        return new OnlineMediaObject($media->objectKey, $bytes, $result->getContentType(), $result->getLastModified());
    }

    public function delete(OnlineEntryMedia $media): void
    {
        // A key that is not there (never uploaded, deleted before) is no error in S3
        $this->stores->client($media->store)->deleteObject([
            'Bucket' => $this->stores->bucket($media->store),
            'Key' => $media->objectKey,
        ])->resolve();
    }

    public function listObjects(OnlineMediaStore $store, string $prefix, null|string $startAfter, int $limit): OnlineMediaObjectList
    {
        $input = [
            'Bucket' => $this->stores->bucket($store),
            'Prefix' => $prefix,
            'MaxKeys' => max(1, min(1000, $limit)),
        ];

        if ($startAfter !== null) {
            $input['StartAfter'] = $startAfter;
        }

        $result = $this->stores->client($store)->listObjectsV2($input);
        $objects = [];

        // This page only - the result would fetch every following page by itself
        foreach ($result->getContents(true) as $object) {
            $key = $object->getKey();

            if ($key === null) {
                continue;
            }

            $objects[] = new OnlineMediaObject($key, (int) $object->getSize(), null, $object->getLastModified());
        }

        $last = $objects === [] ? null : $objects[count($objects) - 1]->key;

        return new OnlineMediaObjectList($objects, $result->getIsTruncated() === true ? $last : null);
    }

    public function deleteObjects(OnlineMediaStore $store, array $keys): array
    {
        if ($keys === []) {
            return [];
        }

        $result = $this->stores->client($store)->deleteObjects([
            'Bucket' => $this->stores->bucket($store),
            'Delete' => [
                'Objects' => array_map(static fn (string $key): array => ['Key' => $key], $keys),
                // Only the failures come back
                'Quiet' => true,
            ],
        ]);

        $failed = [];

        foreach ($result->getErrors() as $error) {
            $key = $error->getKey();

            if ($key !== null) {
                $failed[] = $key;
            }
        }

        return $failed;
    }
}
