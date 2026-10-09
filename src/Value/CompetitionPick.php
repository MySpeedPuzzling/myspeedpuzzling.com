<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

use InvalidArgumentException;
use Ramsey\Uuid\Uuid;

/**
 * The one parser of the "Competition / event" field value (docs/features/events-page/high-frequency-series.md "The
 * field"): `<uuid>` = a one-time event (a bare uuid of an edition, posted by a form an older release rendered, is read
 * the same way - whoever accepts it decides, P2), `series:<uuid>` = a series pick (MySpeedPuzzling finds the edition),
 * `edition:<uuid>` = an explicitly picked edition. The id is always a lower-case uuid.
 */
final readonly class CompetitionPick
{
    private const string SERIES_PREFIX = 'series:';
    private const string EDITION_PREFIX = 'edition:';

    public CompetitionPickKind $kind;

    public string $id;

    public function __construct(CompetitionPickKind $kind, string $id)
    {
        if (Uuid::isValid($id) === false) {
            throw new InvalidArgumentException(sprintf('"%s" is not a uuid.', $id));
        }

        $this->kind = $kind;
        $this->id = strtolower($id);
    }

    public static function event(string $id): self
    {
        return new self(CompetitionPickKind::Event, $id);
    }

    public static function series(string $id): self
    {
        return new self(CompetitionPickKind::Series, $id);
    }

    public static function edition(string $id): self
    {
        return new self(CompetitionPickKind::Edition, $id);
    }

    /**
     * Null for an empty, missing or malformed value - never an exception: the value comes from a form.
     */
    public static function tryFrom(null|string $fieldValue): null|self
    {
        if ($fieldValue === null) {
            return null;
        }

        $value = trim($fieldValue);

        [$kind, $id] = match (true) {
            str_starts_with($value, self::SERIES_PREFIX) => [CompetitionPickKind::Series, substr($value, strlen(self::SERIES_PREFIX))],
            str_starts_with($value, self::EDITION_PREFIX) => [CompetitionPickKind::Edition, substr($value, strlen(self::EDITION_PREFIX))],
            default => [CompetitionPickKind::Event, $value],
        };

        if (Uuid::isValid($id) === false) {
            return null;
        }

        return new self($kind, $id);
    }

    /**
     * What the edit form shows for a time: its series pick, else its linked edition (explicit), else its one-time event.
     */
    public static function ofTime(null|string $competitionId, null|string $seriesPickId, bool $competitionIsEdition): null|self
    {
        if ($seriesPickId !== null) {
            return self::series($seriesPickId);
        }

        if ($competitionId === null) {
            return null;
        }

        return $competitionIsEdition ? self::edition($competitionId) : self::event($competitionId);
    }

    public function fieldValue(): string
    {
        return match ($this->kind) {
            CompetitionPickKind::Event => $this->id,
            CompetitionPickKind::Series => self::SERIES_PREFIX . $this->id,
            CompetitionPickKind::Edition => self::EDITION_PREFIX . $this->id,
        };
    }

    /**
     * The competition a save links explicitly - a one-time event or an edition
     */
    public function competitionId(): null|string
    {
        return $this->kind === CompetitionPickKind::Series ? null : $this->id;
    }

    /**
     * The series of a series pick
     */
    public function seriesId(): null|string
    {
        return $this->kind === CompetitionPickKind::Series ? $this->id : null;
    }

    public function equals(self $other): bool
    {
        return $this->kind === $other->kind && $this->id === $other->id;
    }
}
