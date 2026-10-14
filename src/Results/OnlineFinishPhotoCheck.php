<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * The completeness check of one finish photo as the review shows it (docs/features/online-competitions/README.md §5.8,
 * §5.9 - Phase 3, advisory): `state` = the verdict (complete / gaps / unsure / failed - the check could not run) or
 * `pending` (not checked yet); the suspected gaps as boxes in the photo's own pixels with the photo's size (the page
 * scales them to the size it shows), why, which checks ran, the worker's version, when. Of several checks of a photo
 * (worker versions), the latest verdict - a failed check only when there is no verdict at all (GetOnlineFinishPhotoChecks).
 */
readonly final class OnlineFinishPhotoCheck implements \JsonSerializable
{
    public const string PENDING = 'pending';

    /**
     * @param list<array{x: int, y: int, w: int, h: int}> $gaps
     */
    public function __construct(
        public string $state,
        public null|float $confidence = null,
        public array $gaps = [],
        public null|string $reason = null,
        public null|string $method = null,
        public null|string $version = null,
        public null|int $imageWidth = null,
        public null|int $imageHeight = null,
        public null|int $checkedAtMs = null,
    ) {
    }

    public static function pending(): self
    {
        return new self(self::PENDING);
    }

    /**
     * @return array<string, mixed>
     */
    public function jsonSerialize(): array
    {
        return [
            'state' => $this->state,
            'confidence' => $this->confidence,
            'gaps' => $this->gaps,
            'reason' => $this->reason,
            'method' => $this->method,
            'version' => $this->version,
            'imageWidth' => $this->imageWidth,
            'imageHeight' => $this->imageHeight,
            'checkedAtMs' => $this->checkedAtMs,
        ];
    }
}
