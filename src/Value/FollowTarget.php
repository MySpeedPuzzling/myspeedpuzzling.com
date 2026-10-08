<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use Ramsey\Uuid\Uuid;

/**
 * What a star follows (docs/features/events-page/README.md, "Follow"): a one-time event or a whole series. Its string
 * form `competition:<uuid>` / `series:<uuid>` is what the follow forms post and the page marks stars with.
 */
readonly final class FollowTarget
{
    private function __construct(
        public FollowTargetKind $kind,
        public string $id,
    ) {
    }

    public static function competition(string $competitionId): self
    {
        return new self(FollowTargetKind::Competition, strtolower($competitionId));
    }

    public static function series(string $seriesId): self
    {
        return new self(FollowTargetKind::Series, strtolower($seriesId));
    }

    public static function tryFromString(string $value): null|self
    {
        $value = strtolower(trim($value));

        if (preg_match('/^(competition|series):(.+)$/', $value, $matches) !== 1) {
            return null;
        }

        if (Uuid::isValid($matches[2]) === false) {
            return null;
        }

        return new self(FollowTargetKind::from($matches[1]), $matches[2]);
    }

    public function toString(): string
    {
        return $this->kind->value . ':' . $this->id;
    }

    public function isSeries(): bool
    {
        return $this->kind === FollowTargetKind::Series;
    }
}
