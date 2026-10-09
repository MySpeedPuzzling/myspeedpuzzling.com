<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A competition as the internal API shows it to an admin - every competition, approved, pending or rejected, with
 * every field the API can edit.
 */
readonly final class AdminCompetition
{
    public function __construct(
        public string $competitionId,
        public string $name,
        public null|string $slug,
        public null|string $shortcut,
        public null|string $description,
        public null|string $location,
        public null|string $locationCountryCode,
        // Calendar days of the event (`2026-10-06`)
        public null|string $dateFrom,
        public null|string $dateTo,
        public null|string $link,
        public null|string $registrationLink,
        public null|string $resultsLink,
        public bool $isOnline,
        public null|string $logo,
        public null|string $seriesId,
        public null|string $seriesName,
        public null|string $seriesSlug,
        // The series' organization (an edition's organization is always its series')
        public null|string $seriesOrganizationId,
        public bool $seriesIsDraft,
        // A one-time event's own organization - always null for an edition
        public null|string $organizationId,
        public null|string $organizationName,
        public null|string $organizationSlug,
        // The competition's own draft flag
        public bool $isDraft,
        // "Who can enter" - an edition without its own shows its series'
        public null|string $eligibility,
        public null|string $tagId,
        public null|string $tagName,
        public null|string $approvedAt,
        public null|string $approvedByPlayerId,
        public null|string $rejectedAt,
        public null|string $rejectionReason,
        public bool $publiclyVisible,
        public null|string $createdAt,
        public null|string $addedByPlayerId,
        public null|string $addedByPlayerName,
        public int $roundsCount,
        // The approval part of IsCompetitionPubliclyVisible (SQL_APPROVED) - an approved draft is approved
        public bool $approved = false,
        // Solving times linked to the competition (puzzle_solving_time.competition_id), suspicious ones included
        public int $resultsCount = 0,
        // Of those, the ones in no round (their puzzle is in none of its rounds of their category)
        public int $resultsWithoutRoundCount = 0,
        // Participants who joined (not removed)
        public int $participantsCount = 0,
        // An edition's series rejected - the edition is rejected with it
        public bool $seriesRejected = false,
    ) {
    }

    /**
     * @param array{
     *     id: string,
     *     name: string,
     *     slug: null|string,
     *     shortcut: null|string,
     *     description: null|string,
     *     location: null|string,
     *     location_country_code: null|string,
     *     date_from: null|string,
     *     date_to: null|string,
     *     link: null|string,
     *     registration_link: null|string,
     *     results_link: null|string,
     *     is_online: bool,
     *     logo: null|string,
     *     series_id: null|string,
     *     series_name: null|string,
     *     series_slug: null|string,
     *     series_organization_id: null|string,
     *     series_is_draft: null|bool,
     *     series_rejected_at?: null|string,
     *     organization_id: null|string,
     *     organization_name: null|string,
     *     organization_slug: null|string,
     *     is_draft: bool,
     *     eligibility: null|string,
     *     tag_id: null|string,
     *     tag_name: null|string,
     *     approved_at: null|string,
     *     approved_by_player_id: null|string,
     *     rejected_at: null|string,
     *     rejection_reason: null|string,
     *     publicly_visible: bool,
     *     approved: bool,
     *     created_at: null|string,
     *     added_by_player_id: null|string,
     *     added_by_player_name: null|string,
     *     rounds_count: int,
     *     results_count: int,
     *     results_without_round_count: int,
     *     participants_count: int,
     *     ...
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            competitionId: $row['id'],
            name: $row['name'],
            slug: $row['slug'],
            shortcut: $row['shortcut'],
            description: $row['description'],
            location: $row['location'],
            locationCountryCode: $row['location_country_code'],
            dateFrom: self::isoDate($row['date_from']),
            dateTo: self::isoDate($row['date_to']),
            link: $row['link'],
            registrationLink: $row['registration_link'],
            resultsLink: $row['results_link'],
            isOnline: $row['is_online'],
            logo: $row['logo'],
            seriesId: $row['series_id'],
            seriesName: $row['series_name'],
            seriesSlug: $row['series_slug'],
            seriesOrganizationId: $row['series_organization_id'],
            seriesIsDraft: $row['series_is_draft'] === true,
            organizationId: $row['organization_id'],
            organizationName: $row['organization_name'],
            organizationSlug: $row['organization_slug'],
            isDraft: $row['is_draft'],
            eligibility: $row['eligibility'],
            tagId: $row['tag_id'],
            tagName: $row['tag_name'],
            approvedAt: self::isoDateTime($row['approved_at']),
            approvedByPlayerId: $row['approved_by_player_id'],
            rejectedAt: self::isoDateTime($row['rejected_at']),
            rejectionReason: $row['rejection_reason'],
            publiclyVisible: $row['publicly_visible'],
            createdAt: self::isoDateTime($row['created_at']),
            addedByPlayerId: $row['added_by_player_id'],
            addedByPlayerName: $row['added_by_player_name'],
            roundsCount: $row['rounds_count'],
            approved: $row['approved'],
            resultsCount: $row['results_count'],
            resultsWithoutRoundCount: $row['results_without_round_count'],
            participantsCount: $row['participants_count'],
            seriesRejected: ($row['series_rejected_at'] ?? null) !== null,
        );
    }

    /**
     * A `timestamp without time zone` column holding UTC (the application's time zone) as ISO 8601
     * (`2026-10-06T09:30:00+00:00`).
     */
    public static function isoDateTime(null|string $value): null|string
    {
        if ($value === null) {
            return null;
        }

        return str_replace(' ', 'T', substr($value, 0, 19)) . '+00:00';
    }

    /**
     * The calendar day of a date column (`2026-10-06`) - competition dates are days, stored at midnight.
     */
    public static function isoDate(null|string $value): null|string
    {
        return $value === null ? null : substr($value, 0, 10);
    }

    /**
     * The approval state - drafts aside (`draft` / `hiddenAsDraft` tell those). Editions are never approved one by
     * one - their series is.
     */
    public function status(): string
    {
        // An edition follows its series: rejected with it, pending while it waits (`approved` reads the series)
        if ($this->rejectedAt !== null || $this->seriesRejected) {
            return 'rejected';
        }

        return $this->approved ? 'approved' : 'pending';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'competitionId' => $this->competitionId,
            'name' => $this->name,
            'slug' => $this->slug,
            'shortcut' => $this->shortcut,
            'description' => $this->description,
            'location' => $this->location,
            'locationCountryCode' => $this->locationCountryCode,
            'dateFrom' => $this->dateFrom,
            'dateTo' => $this->dateTo,
            'link' => $this->link,
            'registrationLink' => $this->registrationLink,
            'resultsLink' => $this->resultsLink,
            'isOnline' => $this->isOnline,
            // A recurring event is a series of editions (CompetitionSeries); this competition is one of them
            'isRecurring' => $this->seriesId !== null,
            'series' => $this->seriesId === null ? null : [
                'seriesId' => $this->seriesId,
                'name' => $this->seriesName,
                'slug' => $this->seriesSlug,
                'organizationId' => $this->seriesOrganizationId,
                'draft' => $this->seriesIsDraft,
            ],
            // A one-time event's own organization (an edition's is its series' - series.organizationId)
            'organizationId' => $this->organizationId,
            'organization' => $this->organizationId === null ? null : [
                'organizationId' => $this->organizationId,
                'name' => $this->organizationName,
                'slug' => $this->organizationSlug,
            ],
            'eligibility' => $this->eligibility,
            'logo' => $this->logo,
            'tagId' => $this->tagId,
            'tagName' => $this->tagName,
            'status' => $this->status(),
            // Its own draft flag; hiddenAsDraft = it or its series is a draft
            'draft' => $this->isDraft,
            'hiddenAsDraft' => $this->isDraft || $this->seriesIsDraft,
            'approvedAt' => $this->approvedAt,
            'approvedByPlayerId' => $this->approvedByPlayerId,
            'rejectedAt' => $this->rejectedAt,
            'rejectionReason' => $this->rejectionReason,
            'publiclyVisible' => $this->publiclyVisible,
            'createdAt' => $this->createdAt,
            'addedByPlayerId' => $this->addedByPlayerId,
            'addedByPlayerName' => $this->addedByPlayerName,
            'roundsCount' => $this->roundsCount,
            'resultsCount' => $this->resultsCount,
            'resultsWithoutRoundCount' => $this->resultsWithoutRoundCount,
            'participantsCount' => $this->participantsCount,
        ];
    }
}
