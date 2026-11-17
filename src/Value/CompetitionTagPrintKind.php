<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Value;

/**
 * What the print page of a competition's tags prints (competition_name_tags `?kind=`, competition toolkit
 * B-qr-competitor-page.md §3.4): person name tags (as before the toolkit), a tag per pair/team line-up for the whole
 * competition, a tag per table, or a tag per entry of one round. Each kind's QR is the frozen path of its tag route
 * (R-review-and-plan.md §1.3).
 */
enum CompetitionTagPrintKind: string
{
    case People = 'people';
    case Lineups = 'lineups';
    case Tables = 'tables';
    case Entries = 'entries';

    /**
     * The kind the page opens with: the competition's QR mode decides (a competitor's tag = a person, or a line-up in an
     * event of pairs/teams only); no mode = the name tags of before.
     */
    public static function defaultFor(null|QrTagMode $mode, bool $pairsAndTeamsOnly): self
    {
        return match ($mode) {
            null => self::People,
            QrTagMode::Entrant => $pairsAndTeamsOnly ? self::Lineups : self::People,
            QrTagMode::Table => self::Tables,
            QrTagMode::RoundEntry => self::Entries,
        };
    }

    /**
     * A table's tag stands on the table, readable from both sides; everybody else's is worn or laid down.
     */
    public function defaultLayout(): CompetitionTagPrintLayout
    {
        return $this === self::Tables ? CompetitionTagPrintLayout::Tents : CompetitionTagPrintLayout::Tags;
    }

    /**
     * Sorted by table first where a table is what the tag is about.
     */
    public function sortsByTableByDefault(): bool
    {
        return $this === self::Tables || $this === self::Entries;
    }
}
