<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\EventsPage;

use DateTimeImmutable;
use SpeedPuzzling\Web\Results\EventOccurrence;
use SpeedPuzzling\Web\Results\EventsPage\AgendaRow;
use SpeedPuzzling\Web\Results\EventsPage\ArchiveLine;
use SpeedPuzzling\Web\Results\EventsPage\DateLeaf;
use SpeedPuzzling\Web\Results\EventsPage\ManageRef;
use SpeedPuzzling\Web\Results\EventsPage\Place;
use SpeedPuzzling\Web\Results\EventsPage\RowTag;
use SpeedPuzzling\Web\Results\EventsPage\RowTagType;
use SpeedPuzzling\Web\Results\EventsPage\WhenLabel;
use SpeedPuzzling\Web\Results\EventsViewerData;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\EventOccurrenceStatus;
use SpeedPuzzling\Web\Value\EventsScope;
use SpeedPuzzling\Web\Value\EventTime;
use SpeedPuzzling\Web\Value\FollowTarget;
use SpeedPuzzling\Web\Value\OccurrenceDates;
use SpeedPuzzling\Web\Value\RegistrationAvailability;
use SpeedPuzzling\Web\Value\RowContext;
use SpeedPuzzling\Web\Value\SearchText;

/**
 * One occurrence as an agenda row or an archive line - the row rules of the events page (moved out of
 * EventsPageBuilder, which delegates; EventsPageBuilderTest guards them) shared with the series page
 * (docs/features/events-page/detail-pages-plan.md 1.3).
 *
 * RowContext::SeriesPage - the series is the page: a row is named by its edition/session (EventOccurrence::subtitle(),
 * else its name) with no line under it, carries no "Recurring" and no "Waiting for approval" tag (the page says both),
 * has no star (the header's star follows the series), always shows, and gets the start time of its first round.
 *
 * RowContext::OrganizationPage (docs/features/organizations/README.md) - named as on the events page, "Recurring" kept,
 * no star (the series cards and the header carry them, P19), always shows, and gets the start time of its first round.
 */
readonly final class EventRowFactory
{
    public function __construct(
        private EventUrls $urls,
    ) {
    }

    /**
     * @param array<string, int> $goingCounts competition id (lower case) => spots taken
     */
    public function row(
        EventOccurrence $occurrence,
        EventOccurrenceStatus $status,
        int $indexId,
        array $goingCounts,
        null|EventsViewerData $viewer,
        EventsScope $scope,
        DateTimeImmutable $now,
        DateTimeImmutable $day,
        string $locale,
        null|string $logo,
        RowContext $context = RowContext::EventsPage,
    ): AgendaRow {
        $isEdition = $occurrence->isEdition();
        $longRunning = $occurrence->isLongRunning();
        $isPast = $status === EventOccurrenceStatus::Past;
        $going = $viewer?->isGoing($occurrence->competitionId) ?? false;
        $onSeriesPage = $context === RowContext::SeriesPage;
        // The series page and the organization page: every row shows, no star on it, the first round's time
        $onOwnPage = $onSeriesPage || $context === RowContext::OrganizationPage;

        $followTarget = null;

        if ($onOwnPage === false) {
            if ($isEdition) {
                $followTarget = FollowTarget::series((string) $occurrence->seriesId);
            } elseif ($isPast === false) {
                $followTarget = FollowTarget::competition($occurrence->competitionId);
            }
        }

        $from = $occurrence->startDate?->format('Y-m-d');
        $to = null;

        if ($longRunning) {
            $to = $from;
        } elseif ($occurrence->endDate !== null) {
            $to = $occurrence->endDate->format('Y-m-d');
        }

        return new AgendaRow(
            indexIds: [$indexId],
            isGroup: false,
            title: self::titleOf($occurrence, $context),
            editionName: $onSeriesPage ? null : $occurrence->subtitle(),
            url: $this->urls->occurrence($occurrence),
            leaf: new DateLeaf(
                from: $occurrence->startDate,
                to: $longRunning ? null : $occurrence->endDate,
                tone: self::tone($occurrence->isOnline, $status),
            ),
            place: self::place($occurrence->isOnline, $occurrence->location, $occurrence->countryCode, $locale),
            tags: $this->tags($occurrence, $status, $going, $goingCounts[strtolower($occurrence->competitionId)] ?? 0, $now, $context),
            when: self::when($status, $occurrence->startDate, $day),
            sessions: [],
            status: $status,
            scopeKey: EventsScope::keyOf($occurrence->isOnline, $occurrence->countryCode),
            from: $from,
            to: $to,
            followTarget: $followTarget,
            followName: $isEdition ? (string) $occurrence->seriesName : $occurrence->name,
            following: $followTarget !== null && $viewer !== null && $viewer->follows($followTarget),
            manage: new ManageRef(ManageRef::KIND_COMPETITION, $occurrence->competitionId, $occurrence->reference()->displayName()),
            isPending: $occurrence->isPublic === false,
            visible: $onOwnPage || $scope->matches($occurrence->isOnline, $occurrence->countryCode),
            logo: $logo,
            time: $onOwnPage && $occurrence->firstRound !== null ? EventTime::fromOccurrenceRound($occurrence->firstRound) : null,
        );
    }

    /**
     * One past occurrence (or session) as one archive line
     */
    public function archiveLine(
        EventOccurrence $occurrence,
        int $indexId,
        EventsScope $scope,
        string $locale,
        RowContext $context = RowContext::EventsPage,
    ): ArchiveLine {
        $start = $occurrence->startDate;
        assert($start !== null);
        $onSeriesPage = $context === RowContext::SeriesPage;
        $onOwnPage = $onSeriesPage || $context === RowContext::OrganizationPage;

        return new ArchiveLine(
            indexIds: [$indexId],
            title: self::titleOf($occurrence, $context),
            url: $this->urls->occurrence($occurrence),
            from: $start,
            to: $occurrence->endDate,
            editionCount: 1,
            monthFrom: (int) $start->format('n'),
            monthTo: (int) ($occurrence->endDate ?? $start)->format('n'),
            hasResults: $occurrence->hasResults,
            place: self::place($occurrence->isOnline, $occurrence->location, $occurrence->countryCode, $locale),
            scopeKey: EventsScope::keyOf($occurrence->isOnline, $occurrence->countryCode),
            visible: $onOwnPage || $scope->matches($occurrence->isOnline, $occurrence->countryCode),
            editionName: $onSeriesPage ? null : $occurrence->subtitle(),
            year: (int) $start->format('Y'),
            stateTag: $onOwnPage ? self::stateTag($occurrence, $onSeriesPage) : null,
        );
    }

    /**
     * Draft, else Waiting for approval - the series page leaves the latter out (its editions follow the series), like
     * tags() does for the rows
     */
    private static function stateTag(EventOccurrence $occurrence, bool $onSeriesPage): null|RowTagType
    {
        if ($occurrence->isDraft) {
            return RowTagType::Draft;
        }

        return $occurrence->isPublic === false && $onSeriesPage === false ? RowTagType::WaitingForApproval : null;
    }

    /**
     * WaitingForApproval or Draft, Going, Recurring, Eligibility, registration, Results, RunsUntil, GoingCount - in this
     * order. The series page leaves out WaitingForApproval and Recurring. A draft (only its team gets its row) says Draft
     * everywhere and never "Waiting for approval" - it is submitted by publishing it.
     *
     * @return list<RowTag>
     */
    public function tags(
        EventOccurrence $occurrence,
        EventOccurrenceStatus $status,
        bool $going,
        int $goingCount,
        DateTimeImmutable $now,
        RowContext $context = RowContext::EventsPage,
    ): array {
        $isPast = $status === EventOccurrenceStatus::Past;
        $onSeriesPage = $context === RowContext::SeriesPage;
        $tags = [];

        if ($occurrence->isDraft) {
            $tags[] = new RowTag(RowTagType::Draft);
        } elseif ($occurrence->isPublic === false && $onSeriesPage === false) {
            $tags[] = new RowTag(RowTagType::WaitingForApproval);
        }

        if ($going) {
            $tags[] = new RowTag(RowTagType::Going);
        }

        if ($occurrence->isEdition() && $onSeriesPage === false) {
            $tags[] = new RowTag(RowTagType::Recurring);
        }

        if ($occurrence->eligibility !== null) {
            $tags[] = new RowTag(RowTagType::Eligibility, text: $occurrence->eligibility);
        }

        if ($isPast === false && $going === false) {
            $registration = $this->registrationTag($occurrence, $goingCount, $now);

            if ($registration !== null) {
                $tags[] = $registration;
            }
        }

        if ($isPast && $occurrence->hasResults) {
            $tags[] = new RowTag(RowTagType::Results);
        }

        if ($isPast === false && $occurrence->isLongRunning()) {
            $tags[] = new RowTag(RowTagType::RunsUntil, date: $occurrence->endDate);
        }

        if ($isPast === false && $goingCount > 0) {
            $tags[] = new RowTag(RowTagType::GoingCount, count: $goingCount);
        }

        return $tags;
    }

    public function registrationTag(EventOccurrence $occurrence, int $goingCount, DateTimeImmutable $now): null|RowTag
    {
        $availability = $occurrence->registrationAvailability($now);

        if ($availability === null) {
            return $occurrence->hasRegistrationLink ? new RowTag(RowTagType::Registration) : null;
        }

        return match ($availability) {
            RegistrationAvailability::NotYetOpen => new RowTag(
                RowTagType::RegistrationOpens,
                date: $occurrence->registrationOpensAt !== null
                    ? OccurrenceDates::localDay($occurrence->registrationOpensAt, $occurrence->registrationZone())
                    : null,
            ),
            RegistrationAvailability::Open => $occurrence->capacity !== null && $goingCount >= $occurrence->capacity
                ? new RowTag(RowTagType::FullWaitlist)
                : new RowTag(RowTagType::RegistrationOpen),
            RegistrationAvailability::Closed => new RowTag(RowTagType::RegistrationClosed),
            RegistrationAvailability::NotPublic => null,
        };
    }

    /**
     * "Live", "Tomorrow", "This weekend", "In 13 days" - only for live and upcoming occurrences; nothing beyond
     * RELATIVE_DAYS. Never "Today": an occurrence starting today is live (rounds use it, RoundsTimelineBuilder).
     */
    public static function when(EventOccurrenceStatus $status, null|DateTimeImmutable $start, DateTimeImmutable $day): null|WhenLabel
    {
        if ($status === EventOccurrenceStatus::Live) {
            return new WhenLabel(WhenLabel::LIVE, 0, false);
        }

        if ($status !== EventOccurrenceStatus::Upcoming || $start === null) {
            return null;
        }

        $days = (int) $day->diff($start)->days;

        if ($days === 1) {
            return new WhenLabel(WhenLabel::TOMORROW, 1, true);
        }

        // Friday, Saturday or Sunday of the current (ISO, Monday-first) week - on a Saturday, next Friday is not
        // "this weekend"
        if ($days <= 7 - (int) $day->format('N') && (int) $start->format('N') >= 5) {
            return new WhenLabel(WhenLabel::THIS_WEEKEND, $days, true);
        }

        if ($days <= EventsPageBuilder::RELATIVE_DAYS) {
            return new WhenLabel(WhenLabel::IN_DAYS, $days, $days <= EventsPageBuilder::SOON_DAYS);
        }

        return null;
    }

    /**
     * The events page: the event's name, an edition's series name. The series page: the edition's (and session's) own
     * name, else its name.
     */
    public static function titleOf(EventOccurrence $occurrence, RowContext $context = RowContext::EventsPage): string
    {
        if ($context === RowContext::SeriesPage) {
            return $occurrence->subtitle() ?? $occurrence->name;
        }

        return $occurrence->isEdition() ? (string) $occurrence->seriesName : $occurrence->name;
    }

    /**
     * @return 'in_person'|'online'|'muted'
     */
    public static function tone(bool $isOnline, EventOccurrenceStatus $status): string
    {
        if ($status !== EventOccurrenceStatus::Live && $status !== EventOccurrenceStatus::Upcoming) {
            return DateLeaf::TONE_MUTED;
        }

        return $isOnline ? DateLeaf::TONE_ONLINE : DateLeaf::TONE_IN_PERSON;
    }

    /**
     * The city is left out when the location already holds the country's name (localised or English) - then the
     * country shows alone.
     */
    public static function place(bool $isOnline, null|string $location, null|CountryCode $country, string $locale): Place
    {
        if ($isOnline) {
            return new Place(null, null, null, true);
        }

        $countryName = $country?->localizedName($locale);
        $city = $location !== null && trim($location) !== '' ? trim($location) : null;

        if ($city !== null && $country !== null) {
            $folded = SearchText::fold($city);

            foreach (array_unique([(string) $countryName, $country->value]) as $name) {
                $foldedName = SearchText::fold($name);

                if ($foldedName !== '' && str_contains($folded, $foldedName)) {
                    $city = null;

                    break;
                }
            }
        }

        return new Place($city, $countryName, $country, false);
    }
}
