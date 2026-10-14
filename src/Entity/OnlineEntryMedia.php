<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\Index;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\OnlineMediaKind;
use SpeedPuzzling\Web\Value\OnlineMediaStore;

/**
 * One evidence object of a participant's online entry (docs/features/online-competitions/README.md §5.6, §5.11): a photo
 * or a finish burst the phone uploads straight to object storage through a presigned URL. The row exists before the
 * upload (its id is made by the controller before dispatch); `uploadedAtMs` is set when the phone reports the upload
 * (`media.uploaded`, with a burst's manifest of frames), `verifiedBytes` when the server checked the object is there.
 * `store` follows the presigned upload - the second store takes an upload while the primary is down. `abandonedAt`:
 * the upload never arrived and never will - the phone gave this URL up (its next presign named it as the failed one:
 * RegisterOnlineMediaHandler), or nothing was in the store long after the URL ran out (MarkAbandonedOnlineMediaHandler,
 * with the daily prune); the review says "never arrived" instead of "pending".
 */
#[Entity]
#[Index(columns: ['online_entry_id', 'kind', 'taken_ms'])]
class OnlineEntryMedia
{
    #[Column(type: Types::BIGINT, nullable: true)]
    public null|int $uploadedAtMs = null;

    #[Column(nullable: true)]
    public null|int $verifiedBytes = null;

    #[Column(type: Types::DATETIME_IMMUTABLE, nullable: true)]
    public null|DateTimeImmutable $abandonedAt = null;

    /**
     * A burst's frames in the one uploaded object: [{offset, length, captureMs}]
     *
     * @var null|list<array{offset: int, length: int, captureMs: int|float}>
     */
    #[Column(type: Types::JSONB, nullable: true)]
    public null|array $manifest = null;

    /**
     * The automated completeness check's verdict (Phase 3)
     *
     * @var null|array<string, mixed>
     */
    #[Column(type: Types::JSONB, nullable: true)]
    public null|array $verdict = null;

    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public OnlineEntry $onlineEntry,
        #[Immutable]
        #[Column(length: 16, enumType: OnlineMediaKind::class)]
        public OnlineMediaKind $kind,
        // When the phone took it - epoch ms on its synced clock
        #[Immutable]
        #[Column(type: Types::BIGINT)]
        public int $takenMs,
        // Changes when a new presigned upload sends the object to the other store
        #[Column(length: 16, enumType: OnlineMediaStore::class)]
        public OnlineMediaStore $store,
        // The same key in both stores
        #[Immutable]
        #[Column(length: 255)]
        public string $objectKey,
        #[Immutable]
        #[Column(length: 64)]
        public string $contentType,
        // The size the phone announced - the presigned upload is signed for exactly this length
        #[Immutable]
        #[Column]
        public int $declaredBytes,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $createdAt,
        // The event it belongs to (a finish tap's photo and burst) - the phone's event id, no foreign key: the media can
        // arrive before its event
        #[Immutable]
        #[Column(type: UuidType::NAME, nullable: true)]
        public null|UuidInterface $relatedEventId = null,
    ) {
    }

    /**
     * The phone reported the upload done (`media.uploaded`) - a burst with its frames
     *
     * @param null|list<array{offset: int, length: int, captureMs: int|float}> $manifest
     */
    public function markUploaded(int $uploadedAtMs, null|array $manifest = null): void
    {
        $this->uploadedAtMs = $uploadedAtMs;
        // It arrived after all (a report that waited on a phone offline for long) - the upload wins
        $this->abandonedAt = null;

        if ($manifest !== null) {
            $this->manifest = $manifest;
        }
    }

    /**
     * The upload never arrived and never will: the phone gave the URL up, or the store has nothing long after the URL
     * ran out. Nothing for an upload already reported, nor one marked before (the first moment stays).
     *
     * @return bool whether it is marked now
     */
    public function markAbandoned(DateTimeImmutable $at): bool
    {
        if ($this->uploadedAtMs !== null || $this->abandonedAt !== null) {
            return false;
        }

        $this->abandonedAt = $at;

        return true;
    }

    /**
     * The server found the object and its size (VerifyOnlineMedia)
     */
    public function markVerified(int $bytes): void
    {
        $this->verifiedBytes = $bytes;
    }
}
