<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\OnlineEntryMedia;
use SpeedPuzzling\Web\Value\PresignedUpload;
use SpeedPuzzling\Web\Value\RoundClock;

/**
 * Object storage without object storage - the test environment's OnlineMediaStorage (config/services_test.php), and
 * every environment's until the S3 implementation is bound. Its URLs lead nowhere (`.invalid`); a test plays the phone's
 * upload with upload() and reads deletions from deleted().
 *
 * Deliberately not reset between requests of one kernel (like InMemoryApiUsageCounter): an upload a test made must
 * still be there for the request that verifies it.
 */
final class FakeOnlineMediaStorage implements OnlineMediaStorage
{
    public const string BASE_URL = 'https://online-media.invalid';
    public const int PUT_LIFETIME_SECONDS = 900;

    /** @var array<string, int> "store/key" => bytes */
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
            url: self::BASE_URL . '/' . self::address($media) . '?X-Fake-Signature=put',
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
        return self::BASE_URL . '/' . self::address($media) . '?X-Fake-Signature=get&X-Fake-Expires=' . $ttlSeconds;
    }

    public function head(OnlineEntryMedia $media): null|int
    {
        return $this->objects[self::address($media)] ?? null;
    }

    public function delete(OnlineEntryMedia $media): void
    {
        unset($this->objects[self::address($media)]);
        $this->deleted[] = self::address($media);
    }

    /**
     * The phone's PUT: the object is there now, with this size
     */
    public function upload(OnlineEntryMedia $media, null|int $bytes = null): void
    {
        $this->objects[self::address($media)] = $bytes ?? $media->declaredBytes;
    }

    /**
     * @return list<string> "store/key" of every deleted object, in order
     */
    public function deleted(): array
    {
        return $this->deleted;
    }

    private static function address(OnlineEntryMedia $media): string
    {
        return $media->store->value . '/' . $media->objectKey;
    }
}
