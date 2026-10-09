<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Tests\Query;

use DateTimeImmutable;
use DateTimeZone;
use Doctrine\DBAL\Connection;
use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Message\RevealRoundPuzzleNow;
use SpeedPuzzling\Web\Query\GetEventOccurrences;
use SpeedPuzzling\Web\Results\EventOccurrence;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\CompetitionSeriesFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\EventsPageFixture;
use SpeedPuzzling\Web\Tests\DataFixtures\OrganizationFixture;
use SpeedPuzzling\Web\Tests\SeriesEditionScenario;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\EventOccurrenceStatus;
use SpeedPuzzling\Web\Value\RoundCategory;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;

final class GetEventOccurrencesTest extends KernelTestCase
{
    private GetEventOccurrences $query;
    private DateTimeImmutable $today;

    protected function setUp(): void
    {
        self::bootKernel();
        $this->query = self::getContainer()->get(GetEventOccurrences::class);
        $now = self::getContainer()->get(ClockInterface::class)->now();
        $this->today = new DateTimeImmutable($now->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d'), new DateTimeZone('UTC'));
    }

    public function testTheSeriesOwnRoundsAggregateGivesWhatTheWholeSiteOneGives(): void
    {
        // forSeries() aggregates only the series' rounds - the occurrences, their rounds and results are the same
        foreach ([EventsPageFixture::SERIES_SPRINT_LEAGUE, EventsPageFixture::SERIES_HARBOR_NIGHTS] as $seriesId) {
            $fromAll = array_values(array_filter(
                $this->query->all(true),
                static fn (EventOccurrence $occurrence): bool => $occurrence->seriesId === $seriesId,
            ));

            self::assertNotSame([], $fromAll);
            self::assertEquals($fromAll, $this->query->forSeries($seriesId));
        }
    }

    public function testThePublicSetHasNoUnapprovedRejectedOrRejectedSeriesItems(): void
    {
        $ids = array_map(static fn (EventOccurrence $occurrence): string => $occurrence->competitionId, $this->query->all(false));

        self::assertContains(EventsPageFixture::COMPETITION_RIVERSIDE_OPEN, $ids);
        self::assertContains(EventsPageFixture::EDITION_HARBOR_1, $ids);
        self::assertContains(CompetitionFixture::COMPETITION_WJPC_2024, $ids);
        self::assertNotContains(CompetitionFixture::COMPETITION_UNAPPROVED, $ids);
        self::assertNotContains(CompetitionSeriesFixture::EDITION_UNAPPROVED_1, $ids);
        self::assertNotContains(EventsPageFixture::COMPETITION_GARDEN_SWAP_REJECTED, $ids);
        // Old Mill: approved, then rejected - its edition is gone with it
        self::assertNotContains(EventsPageFixture::EDITION_OLD_MILL, $ids);

        foreach ($this->query->all(false) as $occurrence) {
            self::assertTrue($occurrence->isPublic);
        }
    }

    public function testAdminsAlsoGetTheOnesWaitingForApproval(): void
    {
        $occurrences = $this->byId($this->query->all(true));

        self::assertArrayHasKey(CompetitionFixture::COMPETITION_UNAPPROVED, $occurrences);
        self::assertFalse($occurrences[CompetitionFixture::COMPETITION_UNAPPROVED]->isPublic);
        self::assertArrayHasKey(CompetitionSeriesFixture::EDITION_UNAPPROVED_1, $occurrences);
        self::assertFalse($occurrences[CompetitionSeriesFixture::EDITION_UNAPPROVED_1]->isPublic);
        self::assertTrue($occurrences[EventsPageFixture::COMPETITION_RIVERSIDE_OPEN]->isPublic);
        self::assertArrayNotHasKey(EventsPageFixture::COMPETITION_GARDEN_SWAP_REJECTED, $occurrences);
        self::assertArrayNotHasKey(EventsPageFixture::EDITION_OLD_MILL, $occurrences);
    }

    /**
     * Session 1's round starts at 23:30 in Toronto - in UTC that is already the next day
     */
    public function testAnEditionIsDatedByItsFirstRoundInTheEventsZone(): void
    {
        $occurrences = $this->byId($this->query->all(false));
        $session = $occurrences[EventsPageFixture::EDITION_HARBOR_1];
        $expected = $this->today->modify('first day of +2 months')->modify('+4 days');

        self::assertEquals($expected, $session->startDate);
        self::assertNull($session->endDate);
        self::assertSame(1, $session->roundCount);
        self::assertTrue($session->isOnline);
        self::assertSame(CountryCode::ca, $session->countryCode);
        self::assertSame(EventsPageFixture::SERIES_HARBOR_NIGHTS_NAME, $session->seriesName);
        self::assertSame('Session 1', $session->editionName());
        self::assertSame(EventOccurrenceStatus::Upcoming, $session->status($this->today));
    }

    /**
     * Rounds a month or more apart: one occurrence per round day, in its own zone - 22:00 in New York is the next day
     * in UTC
     */
    public function testAnEditionWithMonthlyRoundsIsOneOccurrencePerSession(): void
    {
        $sessions = array_values(array_filter(
            $this->query->all(false),
            static fn (EventOccurrence $occurrence): bool => $occurrence->competitionId === EventsPageFixture::EDITION_SPRINT_SEASON,
        ));

        self::assertCount(4, $sessions);
        self::assertSame(
            EventsPageFixture::storedSprintRoundDays(self::getContainer()->get(Connection::class)),
            array_map(static fn (EventOccurrence $occurrence): string => (string) $occurrence->startDate?->format('Y-m-d'), $sessions),
        );
        self::assertSame(array_keys(EventsPageFixture::SPRINT_ROUND_DAYS), array_map(static fn (EventOccurrence $occurrence): string => (string) $occurrence->session?->firstRoundId, $sessions));
        self::assertSame(['Sprint 1', 'Sprint 2', 'Sprint 3', 'Sprint 4'], array_map(static fn (EventOccurrence $occurrence): null|string => $occurrence->session?->label, $sessions));
        self::assertSame(
            [EventOccurrenceStatus::Past, EventOccurrenceStatus::Past, EventOccurrenceStatus::Upcoming, EventOccurrenceStatus::Upcoming],
            array_map(fn (EventOccurrence $occurrence): EventOccurrenceStatus => $occurrence->status($this->today), $sessions),
        );
        self::assertNull($sessions[0]->endDate);
        self::assertSame(4, $sessions[0]->roundCount);

        // Each session carries its own round only (docs/features/events-page/high-frequency-series.md "Events pages")
        self::assertSame(
            array_keys(EventsPageFixture::SPRINT_ROUND_DAYS),
            array_map(static fn (EventOccurrence $occurrence): string => implode(',', array_map(static fn ($round): string => $round->id, $occurrence->rounds)), $sessions),
        );
    }

    /**
     * Every occurrence carries its rounds' categories and the names of their revealed puzzles, inside the one statement -
     * never a puzzle its round still keeps secret, nor one hidden on the whole site (high-frequency-series.md, H12 4)
     */
    public function testRoundsCarryTheirCategoryAndOnlyRevealedPuzzleNames(): void
    {
        $scenario = new SeriesEditionScenario(self::getContainer());
        $seriesId = $scenario->series();
        $day = $this->today->modify('-7 days')->format('Y-m-d');
        $edition = $scenario->edition($seriesId, 'Jam No. 153', $day);
        $revealed = $scenario->puzzle('Copper Lighthouse');
        $secret = $scenario->puzzle('Starry Harbor');
        $hiddenEverywhere = $scenario->puzzle('Velvet Comet');
        $scenario->round($edition, RoundCategory::Solo, $day . ' 19:00', puzzleIds: [$revealed, $hiddenEverywhere]);
        $pairsRound = $scenario->round($edition, RoundCategory::Duo, $day . ' 21:00', puzzleIds: [$secret], secret: true);
        // A puzzle created for a round stays hidden on the whole site until its reveal (puzzle.hide_until)
        self::getContainer()->get(Connection::class)->executeStatement(
            "UPDATE puzzle SET hide_until = NOW() + INTERVAL '3 days' WHERE id = :id",
            ['id' => $hiddenEverywhere],
        );

        $occurrence = $this->byId($this->query->forSeries($seriesId))[$edition];
        self::assertSame(['solo', 'duo'], $occurrence->roundCategories());
        self::assertSame(['Copper Lighthouse'], $occurrence->puzzleNames());
        self::assertSame(['Copper Lighthouse'], $this->byId($this->query->all(false))[$edition]->puzzleNames(), 'the events page reads the same');

        // Revealed now - its name is there
        $scenario->dispatch(new RevealRoundPuzzleNow($scenario->roundPuzzleId($pairsRound, $secret)));
        self::assertSame(['Copper Lighthouse', 'Starry Harbor'], $this->byId($this->query->forSeries($seriesId))[$edition]->puzzleNames());
    }

    public function testStatuses(): void
    {
        $occurrences = $this->byId($this->query->all(false));

        $clock = $occurrences[EventsPageFixture::EDITION_CLOCK_LONG];
        self::assertSame(EventOccurrenceStatus::Ongoing, $clock->status($this->today), 'over a month without rounds');
        self::assertTrue($clock->isLongRunning());
        self::assertNull($clock->editionName(), 'named like its series');

        self::assertSame(EventOccurrenceStatus::Tba, $occurrences[EventsPageFixture::COMPETITION_MEADOW_TBA]->status($this->today));
        self::assertTrue($occurrences[EventsPageFixture::COMPETITION_MEADOW_TBA]->hasRegistrationLink);
        self::assertSame(EventOccurrenceStatus::Ongoing, $occurrences[EventsPageFixture::COMPETITION_ENDLESS_RELAY]->status($this->today));
        self::assertSame(EventOccurrenceStatus::DateNotSet, $occurrences[EventsPageFixture::EDITION_HARBOR_UNDATED]->status($this->today));
        self::assertSame(EventOccurrenceStatus::Past, $occurrences[EventsPageFixture::COMPETITION_VALLEY_CUP_LAST_YEAR]->status($this->today));
        self::assertSame(EventOccurrenceStatus::Upcoming, $occurrences[EventsPageFixture::COMPETITION_RIVERSIDE_OPEN]->status($this->today));
    }

    public function testResults(): void
    {
        $occurrences = $this->byId($this->query->all(false));

        self::assertTrue($occurrences[EventsPageFixture::COMPETITION_VALLEY_CUP_LAST_YEAR]->hasResults);
        self::assertFalse($occurrences[EventsPageFixture::COMPETITION_VALLEY_CUP_TWO_YEARS_AGO]->hasResults);
    }

    public function testManagedRegistration(): void
    {
        $riverside = $this->byId($this->query->all(false))[EventsPageFixture::COMPETITION_RIVERSIDE_OPEN];

        self::assertTrue($riverside->registrationManaged);
        self::assertFalse($riverside->hasRegistrationLink);
        self::assertSame(2, $riverside->capacity);
        self::assertSame('Europe/Berlin', $riverside->registrationTimezone);
        self::assertSame('Hamburg', $riverside->location);
    }

    public function testOrderedByStartUndatedLast(): void
    {
        $occurrences = $this->query->all(false);
        $seenUndated = false;
        $previous = null;

        foreach ($occurrences as $occurrence) {
            if ($occurrence->startDate === null) {
                $seenUndated = true;

                continue;
            }

            self::assertFalse($seenUndated, 'dated after undated: ' . $occurrence->name);

            if ($previous !== null) {
                self::assertGreaterThanOrEqual($previous, $occurrence->startDate);
            }

            $previous = $occurrence->startDate;
        }
    }

    /**
     * The series page's rows (detail-pages-plan.md 1.2): every session of the Sprint League, each with its first round
     * and its own Results - only Sprint 1 has a result
     */
    public function testForSeriesListsEverySessionWithItsOwnResults(): void
    {
        $sessions = $this->query->forSeries(EventsPageFixture::SERIES_SPRINT_LEAGUE);

        self::assertCount(4, $sessions);
        self::assertSame(array_keys(EventsPageFixture::SPRINT_ROUND_DAYS), array_map(static fn (EventOccurrence $occurrence): string => (string) $occurrence->firstRound?->id, $sessions));
        self::assertSame([true, false, false, false], array_map(static fn (EventOccurrence $occurrence): bool => $occurrence->hasResults, $sessions));
        self::assertSame('America/New_York', $sessions[2]->firstRound?->zone);
        self::assertFalse($sessions[2]->firstRound->zoneAssumed);

        // The events page reads the same rows
        $onEventsPage = array_values(array_filter(
            $this->query->all(false),
            static fn (EventOccurrence $occurrence): bool => $occurrence->competitionId === EventsPageFixture::EDITION_SPRINT_SEASON,
        ));
        self::assertSame([true, false, false, false], array_map(static fn (EventOccurrence $occurrence): bool => $occurrence->hasResults, $onEventsPage));
    }

    public function testForSeriesIncludesTheUndatedEdition(): void
    {
        $ids = array_map(static fn (EventOccurrence $occurrence): string => $occurrence->competitionId, $this->query->forSeries(EventsPageFixture::SERIES_HARBOR_NIGHTS));

        self::assertEqualsCanonicalizing([
            EventsPageFixture::EDITION_HARBOR_1,
            EventsPageFixture::EDITION_HARBOR_2,
            EventsPageFixture::EDITION_HARBOR_3,
            EventsPageFixture::EDITION_HARBOR_PAST_A,
            EventsPageFixture::EDITION_HARBOR_PAST_B,
            EventsPageFixture::EDITION_HARBOR_UNDATED,
        ], $ids);
        self::assertSame(EventsPageFixture::EDITION_HARBOR_UNDATED, $ids[count($ids) - 1], 'undated last');
    }

    public function testForSeriesListsTheEditionsOfASeriesNotPublic(): void
    {
        // Waiting for approval: its own page lists its editions, not public
        $unapproved = $this->query->forSeries(CompetitionSeriesFixture::SERIES_UNAPPROVED);
        self::assertSame([CompetitionSeriesFixture::EDITION_UNAPPROVED_1], array_map(static fn (EventOccurrence $occurrence): string => $occurrence->competitionId, $unapproved));
        self::assertFalse($unapproved[0]->isPublic);

        // Rejected series: its page still lists its edition - the events page never
        $oldMill = $this->query->forSeries(EventsPageFixture::SERIES_OLD_MILL_REJECTED);
        self::assertSame([EventsPageFixture::EDITION_OLD_MILL], array_map(static fn (EventOccurrence $occurrence): string => $occurrence->competitionId, $oldMill));
        self::assertFalse($oldMill[0]->isPublic);

        self::assertSame([], $this->query->forSeries('not-a-uuid'));
        self::assertSame([], $this->query->forSeries(EventsPageFixture::SERIES_SUMMIT_LEAGUE));
    }

    public function testTheRegistrationLinkShowsOnlyWhileRegistrationIsNotManaged(): void
    {
        $occurrences = $this->byId($this->query->all(false));

        self::assertNull($occurrences[EventsPageFixture::COMPETITION_RIVERSIDE_OPEN]->registrationLink);
        self::assertSame('https://example.com/meadow/register?utm_source=myspeedpuzzling', $occurrences[EventsPageFixture::COMPETITION_MEADOW_TBA]->registrationLink);
    }

    /**
     * docs/features/organizations/README.md: every row carries its organization (a one-time event's own, an edition's
     * series'), its "Who can enter" (own, else the series') and whether it is hidden as a draft
     */
    public function testEveryRowCarriesItsOrganizationEligibilityAndDraftState(): void
    {
        $occurrences = $this->byId($this->query->all(false));

        $lantern = $occurrences[OrganizationFixture::EDITION_LANTERN_1];
        self::assertNotNull($lantern->organization);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $lantern->organization->id);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND_NAME, $lantern->organization->name);
        self::assertSame('RJA', $lantern->organization->shortName);
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND_SLUG, $lantern->organization->slug);
        self::assertTrue($lantern->organization->isPublic);
        self::assertSame('18+', $lantern->eligibility, 'the series\' eligibility');
        self::assertFalse($lantern->isDraft);

        $open = $occurrences[OrganizationFixture::COMPETITION_RIVERBEND_OPEN];
        self::assertSame(OrganizationFixture::ORGANIZATION_RIVERBEND, $open->organization?->id, 'a one-time event\'s own organization');
        self::assertSame('Residents of Riverbend Valley', $open->eligibility);

        self::assertSame('Residents of Riverbend Valley', $occurrences[OrganizationFixture::EDITION_VIRTUAL_NEXT]->eligibility);

        // A draft organization never hides its series: the edition is public, its organization is not
        $harborMeet = $occurrences[OrganizationFixture::EDITION_HARBOR_CLUB_1];
        self::assertTrue($harborMeet->isPublic);
        self::assertSame(OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT, $harborMeet->organization?->id);
        self::assertFalse($harborMeet->organization->isPublic);

        $riverside = $occurrences[EventsPageFixture::COMPETITION_RIVERSIDE_OPEN];
        self::assertNull($riverside->organization);
        self::assertNull($riverside->eligibility);
        self::assertFalse($riverside->isDraft);
    }

    public function testAnEditionsOwnEligibilityWinsOverItsSeries(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition SET eligibility = :eligibility WHERE id = :id',
            ['eligibility' => 'Members only', 'id' => OrganizationFixture::EDITION_LANTERN_2],
        );

        $occurrences = $this->byId($this->query->all(false));

        self::assertSame('Members only', $occurrences[OrganizationFixture::EDITION_LANTERN_2]->eligibility);
        self::assertSame('18+', $occurrences[OrganizationFixture::EDITION_LANTERN_1]->eligibility);
    }

    /**
     * A draft is on no public list - and on the admins' events page neither; one waiting for approval is (for admins)
     */
    public function testDraftsAreNowhereNotEvenForAdmins(): void
    {
        $drafts = [
            OrganizationFixture::COMPETITION_DRAFT_NIGHT,
            OrganizationFixture::COMPETITION_WILLOW_PENDING_DRAFT,
            OrganizationFixture::COMPETITION_DRAFT_PAST,
            OrganizationFixture::EDITION_LANTERN_DRAFT,
            // its series is a draft
            OrganizationFixture::EDITION_QUIET_PINES_1,
        ];

        $public = $this->byId($this->query->all(false));
        $admin = $this->byId($this->query->all(true));

        foreach ($drafts as $id) {
            self::assertArrayNotHasKey($id, $public, $id);
            self::assertArrayNotHasKey($id, $admin, $id);
        }

        self::assertArrayHasKey(OrganizationFixture::EDITION_MAPLE_PENDING_1, $admin);
        self::assertFalse($admin[OrganizationFixture::EDITION_MAPLE_PENDING_1]->isPublic);
        self::assertArrayNotHasKey(OrganizationFixture::EDITION_MAPLE_PENDING_1, $public);

        // Published, the same rows are there
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition SET is_draft = false WHERE id IN (:a, :b)',
            ['a' => OrganizationFixture::COMPETITION_DRAFT_NIGHT, 'b' => OrganizationFixture::EDITION_LANTERN_DRAFT],
        );
        $published = $this->byId($this->query->all(false));
        self::assertArrayHasKey(OrganizationFixture::COMPETITION_DRAFT_NIGHT, $published);
        self::assertArrayHasKey(OrganizationFixture::EDITION_LANTERN_DRAFT, $published);
    }

    public function testForSeriesListsDraftEditionsOnlyForItsTeam(): void
    {
        $public = $this->ids($this->query->forSeries(OrganizationFixture::SERIES_LANTERN_NIGHTS));
        $team = $this->byId($this->query->forSeries(OrganizationFixture::SERIES_LANTERN_NIGHTS, includeDrafts: true));

        self::assertEqualsCanonicalizing([OrganizationFixture::EDITION_LANTERN_1, OrganizationFixture::EDITION_LANTERN_2], $public);
        self::assertEqualsCanonicalizing(
            [OrganizationFixture::EDITION_LANTERN_1, OrganizationFixture::EDITION_LANTERN_2, OrganizationFixture::EDITION_LANTERN_DRAFT],
            array_keys($team),
        );
        self::assertTrue($team[OrganizationFixture::EDITION_LANTERN_DRAFT]->isDraft);
        self::assertFalse($team[OrganizationFixture::EDITION_LANTERN_1]->isDraft);

        // An edition of a draft series is no draft itself, but hidden with its series
        $quietPines = $this->query->forSeries(OrganizationFixture::SERIES_QUIET_PINES_DRAFT);
        self::assertSame([OrganizationFixture::EDITION_QUIET_PINES_1], $this->ids($quietPines));
        self::assertTrue($quietPines[0]->isDraft);
        self::assertFalse($quietPines[0]->isPublic);
    }

    /**
     * The organization page: its series' editions and its one-time events - public ones, or for its team its drafts and
     * the ones waiting for approval too
     */
    public function testForOrganization(): void
    {
        $public = $this->query->forOrganization(OrganizationFixture::ORGANIZATION_RIVERBEND);
        $riverbendPublic = [
            OrganizationFixture::EDITION_LANTERN_1,
            OrganizationFixture::EDITION_LANTERN_2,
            OrganizationFixture::EDITION_VIRTUAL_PAST,
            OrganizationFixture::EDITION_VIRTUAL_NEXT,
            OrganizationFixture::COMPETITION_RIVERBEND_OPEN,
        ];

        self::assertEqualsCanonicalizing($riverbendPublic, $this->ids($public));
        self::assertEqualsCanonicalizing(
            [...$riverbendPublic, OrganizationFixture::EDITION_LANTERN_DRAFT],
            $this->ids($this->query->forOrganization(OrganizationFixture::ORGANIZATION_RIVERBEND, includeDrafts: true)),
        );

        // The organization's own rounds aggregate gives what the whole site's gives
        $fromAll = array_values(array_filter(
            $this->query->all(false),
            static fn (EventOccurrence $occurrence): bool => in_array($occurrence->competitionId, $riverbendPublic, true),
        ));
        self::assertEquals($fromAll, $public);

        // Waiting for approval: only its team sees it
        self::assertSame([], $this->query->forOrganization(OrganizationFixture::ORGANIZATION_MAPLE_PENDING));
        $mapleTeam = $this->query->forOrganization(OrganizationFixture::ORGANIZATION_MAPLE_PENDING, includeDrafts: true);
        self::assertSame([OrganizationFixture::EDITION_MAPLE_PENDING_1], $this->ids($mapleTeam));
        self::assertFalse($mapleTeam[0]->isPublic);

        // A draft organization's published series stays public
        self::assertSame(
            [OrganizationFixture::EDITION_HARBOR_CLUB_1],
            $this->ids($this->query->forOrganization(OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT)),
        );

        self::assertSame([], $this->query->forOrganization('not-a-uuid'));
        self::assertSame([], $this->query->forOrganization(OrganizationFixture::ORGANIZATION_CEDAR_PENDING_DRAFT, includeDrafts: true));
    }

    public function testForOrganizationListsADraftOneTimeEventOnlyForItsTeam(): void
    {
        self::getContainer()->get(Connection::class)->executeStatement(
            'UPDATE competition SET organization_id = :organizationId WHERE id = :id',
            ['organizationId' => OrganizationFixture::ORGANIZATION_RIVERBEND, 'id' => OrganizationFixture::COMPETITION_DRAFT_NIGHT],
        );

        self::assertNotContains(OrganizationFixture::COMPETITION_DRAFT_NIGHT, $this->ids($this->query->forOrganization(OrganizationFixture::ORGANIZATION_RIVERBEND)));

        $team = $this->byId($this->query->forOrganization(OrganizationFixture::ORGANIZATION_RIVERBEND, includeDrafts: true));
        self::assertArrayHasKey(OrganizationFixture::COMPETITION_DRAFT_NIGHT, $team);
        self::assertTrue($team[OrganizationFixture::COMPETITION_DRAFT_NIGHT]->isDraft);
        self::assertSame(1, $team[OrganizationFixture::COMPETITION_DRAFT_NIGHT]->roundCount);
    }

    /**
     * The organizations directory's next dates: the public occurrences of the organizations asked for
     */
    public function testForOrganizations(): void
    {
        $occurrences = $this->query->forOrganizations([OrganizationFixture::ORGANIZATION_RIVERBEND, strtoupper(OrganizationFixture::ORGANIZATION_HARBOR_CLUB_DRAFT)]);

        self::assertEqualsCanonicalizing([
            OrganizationFixture::EDITION_LANTERN_1,
            OrganizationFixture::EDITION_LANTERN_2,
            OrganizationFixture::EDITION_VIRTUAL_PAST,
            OrganizationFixture::EDITION_VIRTUAL_NEXT,
            OrganizationFixture::COMPETITION_RIVERBEND_OPEN,
            OrganizationFixture::EDITION_HARBOR_CLUB_1,
        ], $this->ids($occurrences));

        foreach ($occurrences as $occurrence) {
            self::assertTrue($occurrence->isPublic);
        }

        self::assertSame([], $this->query->forOrganizations([OrganizationFixture::ORGANIZATION_MAPLE_PENDING]));
        self::assertSame([], $this->query->forOrganizations([]));
        self::assertSame([], $this->query->forOrganizations(['not-a-uuid']));
    }

    /**
     * @param list<EventOccurrence> $occurrences
     *
     * @return list<string>
     */
    private function ids(array $occurrences): array
    {
        return array_map(static fn (EventOccurrence $occurrence): string => $occurrence->competitionId, $occurrences);
    }

    /**
     * @param list<EventOccurrence> $occurrences
     *
     * @return array<string, EventOccurrence>
     */
    private function byId(array $occurrences): array
    {
        $byId = [];

        foreach ($occurrences as $occurrence) {
            $byId[$occurrence->competitionId] = $occurrence;
        }

        return $byId;
    }
}
