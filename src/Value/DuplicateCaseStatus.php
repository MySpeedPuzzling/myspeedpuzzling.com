<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

enum DuplicateCaseStatus: string
{
    case Open = 'open';
    // The person kept one copy and deleted the other
    case CopyDeleted = 'copy_deleted';
    // The person confirmed two different solves
    case BothReal = 'both_real';
    // Tier A: the newer copy was removed automatically
    case AutoRemoved = 'auto_removed';
    // The automatic removal was undone - the person says it was another solve
    case Undone = 'undone';
    // The pair no longer matches (a copy deleted or edited elsewhere, merged away)
    case Gone = 'gone';

    /**
     * English, for the admin overview (/admin/duplicate-results) only.
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::CopyDeleted => 'Copy deleted',
            self::BothReal => 'Both real',
            self::AutoRemoved => 'Removed automatically',
            self::Undone => 'Removal undone',
            self::Gone => 'Gone',
        };
    }

    /**
     * One copy is gone because somebody decided it was a duplicate.
     *
     * @return list<self>
     */
    public static function resolved(): array
    {
        return [self::CopyDeleted, self::AutoRemoved];
    }

    /**
     * Both results stay because they are two different solves.
     *
     * @return list<self>
     */
    public static function confirmedReal(): array
    {
        return [self::BothReal, self::Undone];
    }
}
