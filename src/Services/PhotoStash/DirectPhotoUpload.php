<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PhotoStash;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Exceptions\PhotoUploadRefused;
use SpeedPuzzling\Web\Services\OnlineRounds\OnlineMediaStores;
use SpeedPuzzling\Web\Services\OnlineRounds\S3QueryPresigner;
use SpeedPuzzling\Web\Value\OnlineMediaStore;
use SpeedPuzzling\Web\Value\PhotoUploadLimits;
use SpeedPuzzling\Web\Value\PresignedPhotoUpload;
use SpeedPuzzling\Web\Value\UploadedImageMetadata;

/**
 * A photo the browser uploads in the background, straight to object storage, while the player is still filling in the
 * form (docs/features/photo-uploads/README.md). No byte goes through PHP: a slow phone upload never holds a FrankenPHP
 * worker and never meets the edge's request timeout; the form's submit then carries only the PhotoStash token.
 *
 * The photo lands in the PhotoStash (`tmp-uploads/<player>/<token>`, PhotoStash::objectKey()), so everything the stash
 * does works for it unchanged: restore() before the form validates it (the form's own constraints decide - a non-image
 * or an oversized file is refused there exactly like an upload), keep() for a refused form, expiry and the daily prune.
 *
 * The presigned `PUT` is signed for the declared type and exact length (S3QueryPresigner), so the store refuses any
 * other upload; the same bucket CORS rule as the online competitions' evidence lets the site's pages send it
 * (OnlineMediaBucketCors).
 */
final readonly class DirectPhotoUpload
{
    // Long enough for a slow upload to start, short enough that a URL leaking from a phone is soon worthless. The
    // browser asks again (same token) for every attempt after it ran out
    public const int PUT_LIFETIME_SECONDS = 900;

    public function __construct(
        private PhotoStash $photoStash,
        private OnlineMediaStores $stores,
        private S3QueryPresigner $presigner,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @param null|string $token a reservation of this player to sign again (a retry) - else a new one is made
     * @param null|string $replaces the player's earlier reservation for the same field (another photo picked) - let go
     * @param null|UploadedImageMetadata $browserMetadata what the browser read from the photo before it shrank it
     *        (BrowserImageSummary) - kept with a new reservation, stored when the photo itself has no EXIF left
     *
     * @throws PhotoUploadRefused
     */
    public function start(
        string $playerId,
        string $fileName,
        string $mime,
        int $bytes,
        null|string $token = null,
        null|string $replaces = null,
        null|UploadedImageMetadata $browserMetadata = null,
    ): PresignedPhotoUpload {
        $mime = strtolower(trim($mime));

        if ($bytes < 1) {
            throw new PhotoUploadRefused('empty');
        }

        if ($bytes > PhotoUploadLimits::MAX_PHOTO_BYTES) {
            throw new PhotoUploadRefused('too_large');
        }

        if (!in_array($mime, PhotoUploadLimits::MIME_TYPES, true)) {
            throw new PhotoUploadRefused('type');
        }

        $fileName = mb_substr(trim(str_replace(["\r", "\n", '/', '\\'], ' ', $fileName)), 0, 200);

        if ($token !== null && $token !== '' && $this->photoStash->reservedBytes($token, $playerId) !== $bytes) {
            // Expired, never this player's, or made for another file - a fresh place then
            $token = null;
        }

        if ($token === null || $token === '') {
            $token = $this->photoStash->reserve($playerId, $fileName, $mime, $bytes, $browserMetadata)
                ?? throw new PhotoUploadRefused('storage');
        }

        if ($replaces !== null && $replaces !== '' && $replaces !== $token && $this->photoStash->reservedBytes($replaces, $playerId) !== null) {
            $this->photoStash->discard($replaces, $playerId);
        }

        $now = $this->clock->now();
        $headers = [
            'Content-Type' => $mime,
            'Content-Length' => (string) $bytes,
        ];

        return new PresignedPhotoUpload(
            token: $token,
            url: $this->presigner->presign(
                $this->stores->publicLocation(OnlineMediaStore::Primary),
                'PUT',
                $this->photoStash->objectKey($playerId, $token),
                $headers,
                $now,
                self::PUT_LIFETIME_SECONDS,
            ),
            headers: $headers,
            expiresAtMs: ($now->getTimestamp() + self::PUT_LIFETIME_SECONDS) * 1000,
        );
    }
}
