<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\OnlineRounds;

use ImagickException;
use League\Flysystem\Filesystem;
use Psr\Clock\ClockInterface;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\OnlineReferenceImageUnreadable;
use SpeedPuzzling\Web\Services\ImageOptimizer;
use SpeedPuzzling\Web\Value\OnlineMediaStore;
use Symfony\Component\HttpFoundation\File\UploadedFile;

/**
 * The organiser's reference image of an online round - a clean picture of the whole motif for the completeness check's
 * grid matching (docs/features/online-competitions/README.md §5.8), used instead of the round puzzle's catalogue image
 * (a box photo with borders, logos and text - or a HEIC OpenCV does not read) by OnlineCvInputs.
 *
 * - **Stored as a JPEG** (ImageOptimizer::toJpeg()): the EXIF orientation applied, the longer side at most
 *   MAX_DIMENSION px, no metadata but the colour profile. The worker compares the motif at about 30 px per piece edge
 *   (completeness.py check_grid: ~1,100 px wide for 1,000 pieces, ~2,700 px for 6,000) - 3,000 px covers every puzzle
 *   an online round uses and is 9 MP at most: far below the worker's 50 MP and 20 MiB caps (a 3,000 px JPEG at quality
 *   90 weighs 1-4 MB), so the worker never refuses it as too large.
 * - **Its key** `online/{competitionId}/{roundId}/reference/{uuidv7}.jpg` - unguessable, a new one for every upload, in
 *   the primary store (the site's own bucket, written like every upload of the site through Flysystem). Inside the
 *   evidence prefix on purpose: the images proxy refuses `online/` (lily.srv, 2026-10-14), so a secret puzzle's motif
 *   is never public and no image cache keeps a removed one - the organiser's preview is a short presigned GET. It is
 *   not evidence: the evidence prune works on evidence rows only, and the orphan sweep counts a key an online round holds
 *   as its reference as having its row (GetOnlineMediaObjectKeys) - it deletes one only when no round holds it any more
 *   (the round or its event deleted, an upload whose save rolled back), and a day after it was written at the earliest.
 *   A replaced or removed reference is deleted after the commit (DeleteOnlineReferenceImage).
 */
final readonly class OnlineReferenceImages
{
    public const int MAX_DIMENSION = 3_000;
    public const int JPEG_QUALITY = 90;
    // The organiser's preview on the settings page (the page itself is never cached)
    public const int PREVIEW_URL_SECONDS = 1_800;
    private const string KEY_PATTERN = '~^online/[0-9a-f-]{36}/[0-9a-f-]{36}/reference/[0-9a-f-]{36}\.jpg$~';

    public function __construct(
        private Filesystem $filesystem,
        private ImageOptimizer $imageOptimizer,
        private OnlineMediaStores $stores,
        private S3QueryPresigner $presigner,
        private ClockInterface $clock,
    ) {
    }

    public static function objectKey(string $competitionId, string $roundId, string $imageId): string
    {
        return sprintf('online/%s/%s/reference/%s.jpg', strtolower($competitionId), strtolower($roundId), strtolower($imageId));
    }

    public static function isObjectKey(string $key): bool
    {
        return preg_match(self::KEY_PATTERN, $key) === 1;
    }

    /**
     * Converts the upload and stores it under a new key - answers the key. Nothing is stored when the picture cannot be
     * read.
     *
     * @throws OnlineReferenceImageUnreadable
     */
    public function store(UploadedFile $image, string $competitionId, string $roundId): string
    {
        try {
            $jpeg = $this->imageOptimizer->toJpeg($image->getPathname(), self::MAX_DIMENSION, self::JPEG_QUALITY);
        } catch (ImagickException $e) {
            throw new OnlineReferenceImageUnreadable($e);
        }

        $key = self::objectKey($competitionId, $roundId, Uuid::uuid7()->toString());
        $this->filesystem->write($key, $jpeg);

        return $key;
    }

    /**
     * A short-lived URL for the organiser's browser (the store's public endpoint)
     */
    public function previewUrl(string $key): string
    {
        return $this->presigner->presign(
            $this->stores->publicLocation(OnlineMediaStore::Primary),
            'GET',
            $key,
            [],
            $this->clock->now(),
            self::PREVIEW_URL_SECONDS,
        );
    }

    public function delete(string $key): void
    {
        $this->filesystem->delete($key);
    }
}
