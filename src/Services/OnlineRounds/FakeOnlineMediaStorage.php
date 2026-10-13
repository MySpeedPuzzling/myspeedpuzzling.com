<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use DateTimeImmutable;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Value\OnlineMediaObject;
use SpeedPuzzling\Web\Value\OnlineMediaObjectList;
use SpeedPuzzling\Web\Value\OnlineMediaStore;
use SpeedPuzzling\Web\Value\PresignedUpload;
use SpeedPuzzling\Web\Value\RoundClock;

/**
 * Object storage without object storage - the test environment's OnlineMediaStorage (config/services_test.php). Its
 * URLs lead nowhere (`.invalid`); a test plays the phone's upload with upload(), puts any other object with put()
 * (the orphan sweep's leftovers) and reads deletions from deleted().
 *
 * Deliberately not reset between requests of one kernel (like InMemoryApiUsageCounter): an upload a test made must
 * still be there for the request that verifies it.
 */
final class FakeOnlineMediaStorage implements OnlineMediaStorage
{
    public const string BASE_URL = 'https://online-media.invalid';
    public const int PUT_LIFETIME_SECONDS = 900;

    /** @var array<string, array{bytes: int, contentType: string, modifiedAt: DateTimeImmutable}> "store/key" => object */
    private array $objects = [];

    /** @var list<string> "store/key" of every deleted object, in order */
    private array $deleted = [];

    public function __construct(
        private readonly ClockInterface $clock,
    ) {
    }

    public function presignPut(OnlineEntryMedia $media): PresignedUpload
    {
        return new PresignedUpload(
            url: self::BASE_URL . '/' . self::address($media->store, $media->objectKey) . '?X-Fake-Signature=put',
            headers: [
                'Content-Type' => $media->contentType,
                'Content-Length' => (string) $media->declaredBytes,
            ],
            expiresAtMs: RoundClock::milliseconds($this->clock->now()) + self::PUT_LIFETIME_SECONDS * 1000,
            store: $media->store,
        );
    }

    public function presignGet(OnlineEntryMedia $media, int $ttlSeconds): string
    {
        return self::BASE_URL . '/' . self::address($media->store, $media->objectKey) . '?X-Fake-Signature=get&X-Fake-Expires=' . $ttlSeconds;
    }

    public function head(OnlineEntryMedia $media): null|int
    {
        return $this->inspect($media)?->bytes;
    }

    public function inspect(OnlineEntryMedia $media): null|OnlineMediaObject
    {
        $object = $this->objects[self::address($media->store, $media->objectKey)] ?? null;

        return $object === null ? null : new OnlineMediaObject($media->objectKey, $object['bytes'], $object['contentType'], $object['modifiedAt']);
    }

    public function delete(OnlineEntryMedia $media): void
    {
        $this->deleteObjects($media->store, [$media->objectKey]);
    }

    public function listObjects(OnlineMediaStore $store, string $prefix, null|string $startAfter, int $limit): OnlineMediaObjectList
    {
        $keys = [];

        foreach (array_keys($this->objects) as $address) {
            [$objectStore, $key] = explode('/', $address, 2);

            if ($objectStore === $store->value && str_starts_with($key, $prefix) && ($startAfter === null || strcmp($key, $startAfter) > 0)) {
                $keys[] = $key;
            }
        }

        sort($keys, SORT_STRING);
        $page = array_slice($keys, 0, $limit);
        $objects = [];

        foreach ($page as $key) {
            $object = $this->objects[self::address($store, $key)];
            $objects[] = new OnlineMediaObject($key, $object['bytes'], $object['contentType'], $object['modifiedAt']);
        }

        $last = $page === [] ? null : $page[count($page) - 1];

        return new OnlineMediaObjectList($objects, count($keys) > count($page) ? $last : null);
    }

    public function deleteObjects(OnlineMediaStore $store, array $keys): array
    {
        foreach ($keys as $key) {
            unset($this->objects[self::address($store, $key)]);
            $this->deleted[] = self::address($store, $key);
        }

        return [];
    }

    /**
     * The phone's PUT: the object is there now, with this size (and type - the declared one unless given)
     */
    public function upload(OnlineEntryMedia $media, null|int $bytes = null, null|string $contentType = null): void
    {
        $this->put($media->store, $media->objectKey, $bytes ?? $media->declaredBytes, $contentType ?? $media->contentType);
    }

    /**
     * Any object, as if somebody wrote it at that moment (an evidence object whose row is gone)
     */
    public function put(OnlineMediaStore $store, string $key, int $bytes, string $contentType = 'image/jpeg', null|DateTimeImmutable $modifiedAt = null): void
    {
        $this->objects[self::address($store, $key)] = [
            'bytes' => $bytes,
            'contentType' => $contentType,
            'modifiedAt' => $modifiedAt ?? $this->clock->now(),
        ];
    }

    /**
     * @return list<string> "store/key" of every deleted object, in order
     */
    public function deleted(): array
    {
        return $this->deleted;
    }

    private static function address(OnlineMediaStore $store, string $key): string
    {
        return $store->value . '/' . $key;
    }
}
