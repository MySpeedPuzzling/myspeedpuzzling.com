<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests;

use Doctrine\DBAL\Connection;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

/**
 * `ConvertCompetitionToSeries` with `keepAsEdition: false` deletes the competition row (docs/features/events-page/
 * high-frequency-series.md "The conversion tool", P13). A table it forgets either fails every such conversion of an
 * event with rows in it (no cascade) or loses those rows silently (cascade). So every foreign key to `competition` is
 * listed here with what the conversion does with its rows, and a new one fails until somebody decides.
 */
final class ConvertCompetitionForeignKeyCoverageTest extends KernelTestCase
{
    private const string MOVED = 'moved to the series';
    private const string REFUSED = 'refused - CompetitionNotConvertible names it, nothing changes';
    private const string DELETED = 'deleted by the handler';
    private const string REPOINTED = 'repointed to the series';
    private const string CASCADES = 'cascades with the competition';

    private const array WHAT_THE_CONVERSION_DOES = [
        // Its maintainers become the series' maintainers first; the links go with the competition
        'competition_maintainer.competition_id' => self::CASCADES,
        // SeriesConversionBlocker::PageSections
        'competition_page_section.competition_id' => self::REFUSED,
        // SeriesConversionBlocker::Participants unless dropParticipants - then deleted (removed ones always)
        'competition_participant.competition_id' => self::DELETED,
        // SeriesConversionBlocker::Referees
        'competition_referee.competition_id' => self::REFUSED,
        // SeriesConversionBlocker::Rounds (and with them OfficialResults)
        'competition_round.competition_id' => self::REFUSED,
        // Old addresses that led to the event lead to the series (+ a new row for the event's own address)
        'event_url_redirect.competition_id' => self::REPOINTED,
        // The followers follow the series
        'followed_competition.competition_id' => self::MOVED,
        // The participant sheet's change trail goes with its participants
        'participant_sheet_change_receipt.competition_id' => self::DELETED,
        // Every time becomes a series-level pick of the series (one bulk UPDATE)
        'puzzle_solving_time.competition_id' => self::MOVED,
        // SeriesConversionBlocker::MarketplaceMarks
        'sell_swap_list_item_event.competition_id' => self::REFUSED,
    ];

    public function testEveryForeignKeyToCompetitionSaysWhatTheConversionDoesWithIt(): void
    {
        self::bootKernel();

        $foreignKeys = self::getContainer()->get(Connection::class)->fetchFirstColumn(<<<SQL
SELECT c.conrelid::regclass::text || '.' || a.attname::text
FROM pg_constraint c
JOIN pg_attribute a ON a.attrelid = c.conrelid AND a.attnum = ANY (c.conkey)
WHERE c.confrelid = 'competition'::regclass AND c.contype = 'f'
ORDER BY 1
SQL);

        $listed = array_keys(self::WHAT_THE_CONVERSION_DOES);
        sort($listed);

        self::assertSame(
            $listed,
            $foreignKeys,
            'A foreign key to competition appeared or went away - decide what ConvertCompetitionToSeriesHandler (keepAsEdition: false) does with its rows and list it here',
        );
    }
}
