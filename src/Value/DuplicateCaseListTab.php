<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * The tabs of the admin list of duplicate cases (/admin/duplicate-results).
 */
enum DuplicateCaseListTab: string
{
    case Open = 'open';
    case Resolved = 'resolved';
    case ConfirmedReal = 'confirmed';
    case Gone = 'gone';

    /**
     * @return list<DuplicateCaseStatus>
     */
    public function statuses(): array
    {
        return match ($this) {
            self::Open => [DuplicateCaseStatus::Open],
            self::Resolved => DuplicateCaseStatus::resolved(),
            self::ConfirmedReal => DuplicateCaseStatus::confirmedReal(),
            self::Gone => [DuplicateCaseStatus::Gone],
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::Resolved => 'Resolved',
            self::ConfirmedReal => 'Confirmed real',
            self::Gone => 'Gone',
        };
    }
}
