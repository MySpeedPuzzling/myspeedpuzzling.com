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

/**
 * The description of one photo in a player's PhotoStash (docs/features/photo-uploads/README.md "Where the stash keeps
 * what"): a photo kept for a refused form or a place reserved for a background upload. The photo itself (and its
 * preview) stays in object storage under `tmp-uploads/<player>/<token>` - this row is what makes the token work.
 *
 * `storedAt` = the expiry clock (PhotoStash::KEEP_HOURS), never moves. `touchedAt` = recency for the cap of a few photos
 * per player: a preview made for a refused form makes the photo recent again.
 */
#[Entity]
#[Index(columns: ['player_id', 'touched_at'])]
class PhotoStashEntry
{
    /**
     * @param null|array<mixed> $browserMetadata UploadedImageMetadata::toArray() of what the browser read from the photo
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(length: 32)]
        public string $token,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public Player $player,
        #[Immutable]
        #[Column(type: Types::TEXT)]
        public string $name,
        #[Immutable]
        #[Column(type: Types::TEXT)]
        public string $mime,
        #[Column]
        public bool $preview,
        // Set for a reservation only (DirectPhotoUpload signs the upload for exactly this many bytes)
        #[Immutable]
        #[Column(nullable: true)]
        public null|int $bytes,
        #[Immutable]
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $storedAt,
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $touchedAt,
        #[Column(type: Types::JSON, nullable: true, options: ['jsonb' => true])]
        public null|array $browserMetadata,
    ) {
    }

    /**
     * A refused form showed the photo again: it has a preview now and is recent again. A summary the reservation came
     * with wins over a later one.
     *
     * @param null|array<mixed> $browserMetadata
     */
    public function previewMade(DateTimeImmutable $at, null|array $browserMetadata): void
    {
        $this->preview = true;
        $this->touchedAt = $at;
        $this->browserMetadata ??= $browserMetadata;
    }
}
