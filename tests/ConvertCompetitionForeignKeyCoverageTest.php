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
    private const string DETACHED = 'set null - the row stays and knows its event is gone';

    private const array WHAT_THE_CONVERSION_DOES = [
        // Its maintainers become the series' maintainers first; the links go with the competition
        'competition_maintainer.competition_id' => self::CASCADES,
        // Advancement steps (competition toolkit A8) connect the event's rounds - an event with rounds is refused
        // (SeriesConversionBlocker::Rounds), and a step without rounds is never kept, so none is ever there to go
        'competition_advancement_step.competition_id' => self::CASCADES,
        // Timer screens and stream overlays (competition toolkit) - SeriesConversionBlocker::Displays (their designs,
        // pictures and links would cascade away)
        'competition_display.competition_id' => self::REFUSED,
        // SeriesConversionBlocker::PageSections
        'competition_page_section.competition_id' => self::REFUSED,
        // SeriesConversionBlocker::Participants unless dropParticipants - then deleted (removed ones always)
        'competition_participant.competition_id' => self::DELETED,
        // SeriesConversionBlocker::Referees
        'competition_referee.competition_id' => self::REFUSED,
        // SeriesConversionBlocker::Rounds (and with them OfficialResults)
        'competition_round.competition_id' => self::REFUSED,
        // Standings (competition toolkit A6) sum the event's rounds - an event with rounds is refused
        // (SeriesConversionBlocker::Rounds), so only an empty standing of a round-less event goes with it
        'competition_standing.competition_id' => self::CASCADES,
        // An organizer's results sheet tab (docs/features/event-results-import): its event has a round, so the conversion
        // refuses it (SeriesConversionBlocker::Rounds); were it gone, the tab is "detached" and never imports it again
        'event_results_sheet_tab.competition_id' => self::DETACHED,
        // Old addresses that led to the event lead to the series (+ a new row for the event's own address)
        'event_url_redirect.competition_id' => self::REPOINTED,
        // The followers follow the series
        'followed_competition.competition_id' => self::MOVED,
        // Participant link requests (participant-linking.md §3.3) are about the event's participants - an event with
        // participants converts only with dropParticipants, and its requests cascade with them (and with the event)
        'participant_link_request.competition_id' => self::CASCADES,
        // The participant sheet's change trail goes with its participants
        'participant_sheet_change_receipt.competition_id' => self::DELETED,
        // Partner finder listings (and their requests) are going-intent like the participants the conversion drops
        'partner_listing.competition_id' => self::CASCADES,
        // Every time becomes a series-level pick of the series (one bulk UPDATE)
        'puzzle_solving_time.competition_id' => self::MOVED,
        // SeriesConversionBlocker::MarketplaceMarks
        'sell_swap_list_item_event.competition_id' => self::REFUSED,
        // "Link my results" waiting for an event to be approved (creation-wizard.md "Backfill") - the conversion is for
        // public events, whose links never wait (applied by the cron once public); a row still there goes with the event
        // and its time stays unlinked
        'waiting_event_link.competition_id' => self::CASCADES,
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
