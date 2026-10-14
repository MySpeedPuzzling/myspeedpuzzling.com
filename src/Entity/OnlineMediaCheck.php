<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Entity;

use DateTimeImmutable;
use Doctrine\DBAL\Types\Types;
use Doctrine\ORM\Mapping\Column;
use Doctrine\ORM\Mapping\Entity;
use Doctrine\ORM\Mapping\Id;
use Doctrine\ORM\Mapping\JoinColumn;
use Doctrine\ORM\Mapping\ManyToOne;
use Doctrine\ORM\Mapping\UniqueConstraint;
use JetBrains\PhpStorm\Immutable;
use Ramsey\Uuid\Doctrine\UuidType;
use Ramsey\Uuid\UuidInterface;
use SpeedPuzzling\Web\Value\OnlineFinishCheckVerdict;

/**
 * The automated completeness check of one finish photo (docs/features/online-competitions/README.md §5.8, Phase 3 -
 * advisory: the review shows it and orders its queue by it, nothing decides by it). One row per photo per worker
 * version (`workerVersion`, the CV worker's answer - tools/online-cv/), so a re-check with a newer worker adds a row
 * and the older verdict stays for comparison; a forced re-check by the same version replaces its row. A check that could
 * not run (the worker refused the photo, or never answered after the retries - CheckOnlineFinishPhotoHandler) is the
 * row of NO_VERSION with the verdict `failed` and the `error`; a later successful check adds its own row.
 *
 * `gaps`: the suspected holes as boxes `{x, y, w, h}` in the photo's own pixels (`imageWidth` × `imageHeight` - the
 * review scales them to the shown size); `details`: what the worker reported besides (each check's own verdict, the
 * inputs it had - the empty mat, the reference) - research material for the bench, never read by the app.
 */
#[Entity]
#[UniqueConstraint(columns: ['media_id', 'worker_version'])]
class OnlineMediaCheck
{
    // The version of a check that could not run - there was no worker answer to take one from
    public const string NO_VERSION = '-';

    /**
     * @param list<array{x: int, y: int, w: int, h: int}> $gaps
     * @param null|array<string, mixed> $details
     */
    public function __construct(
        #[Id]
        #[Immutable]
        #[Column(type: UuidType::NAME, unique: true)]
        public UuidInterface $id,
        #[Immutable]
        #[ManyToOne]
        #[JoinColumn(nullable: false, onDelete: 'CASCADE')]
        public OnlineEntryMedia $media,
        #[Immutable]
        #[Column(length: 32)]
        public string $workerVersion,
        #[Column(length: 16, enumType: OnlineFinishCheckVerdict::class)]
        public OnlineFinishCheckVerdict $verdict,
        #[Column(type: Types::DATETIME_IMMUTABLE)]
        public DateTimeImmutable $checkedAt,
        // 0-1, the worker's own measure of how sure it is - null for a check that could not run
        #[Column(nullable: true)]
        public null|float $confidence = null,
        #[Column(type: Types::JSONB, options: ['default' => '[]'])]
        public array $gaps = [],
        #[Column(type: Types::TEXT, nullable: true)]
        public null|string $reason = null,
        // holes+grid | holes | grid | none - which checks the worker could run (an empty mat, a reference image)
        #[Column(length: 32, nullable: true)]
        public null|string $method = null,
        // How long the worker took, downloads included
        #[Column(nullable: true)]
        public null|int $ms = null,
        #[Column(nullable: true)]
        public null|int $imageWidth = null,
        #[Column(nullable: true)]
        public null|int $imageHeight = null,
        #[Column(type: Types::JSONB, nullable: true)]
        public null|array $details = null,
        // Why a check could not run (the worker's refusal, a timeout) - null for a verdict
        #[Column(length: 255, nullable: true)]
        public null|string $error = null,
        // The tries this row took (a busy or unreachable worker is asked again later)
        #[Column(options: ['default' => 1])]
        public int $attempts = 1,
    ) {
    }

    /**
     * A forced re-check by the same worker version - its answer replaces this one
     *
     * @param list<array{x: int, y: int, w: int, h: int}> $gaps
     * @param null|array<string, mixed> $details
     */
    public function checkedAgain(
        OnlineFinishCheckVerdict $verdict,
        DateTimeImmutable $checkedAt,
        null|float $confidence,
        array $gaps,
        null|string $reason,
        null|string $method,
        null|int $ms,
        null|int $imageWidth,
        null|int $imageHeight,
        null|array $details,
        int $attempts,
    ): void {
        $this->verdict = $verdict;
        $this->checkedAt = $checkedAt;
        $this->confidence = $confidence;
        $this->gaps = $gaps;
        $this->reason = $reason;
        $this->method = $method;
        $this->ms = $ms;
        $this->imageWidth = $imageWidth;
        $this->imageHeight = $imageHeight;
        $this->details = $details;
        $this->error = null;
        $this->attempts = $attempts;
    }

    /**
     * The check could not run again (NO_VERSION's row) - the latest reason and moment
     */
    public function failedAgain(string $error, DateTimeImmutable $checkedAt, int $attempts): void
    {
        $this->verdict = OnlineFinishCheckVerdict::Failed;
        $this->error = $error;
        $this->checkedAt = $checkedAt;
        $this->attempts = $attempts;
    }
}
