<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * A series as the internal API shows it to an admin - approved, pending, rejected or a draft, with every field the API
 * can edit (docs/features/internal-api.md "Organizations, series and drafts").
 */
readonly final class AdminSeries
{
    public function __construct(
        public string $seriesId,
        public string $name,
        public null|string $slug,
        public null|string $shortcut,
        public null|string $description,
        public null|string $link,
        public bool $isOnline,
        public null|string $location,
        public null|string $locationCountryCode,
        public null|string $logo,
        public null|string $organizationId,
        public null|string $organizationName,
        public null|string $organizationSlug,
        // "Who can enter" (editions without their own show it) and "When it happens"
        public null|string $eligibility,
        public null|string $schedule,
        public bool $isDraft,
        public null|string $approvedAt,
        public null|string $approvedByPlayerId,
        public null|string $rejectedAt,
        public null|string $rejectionReason,
        // IsSeriesPubliclyVisible - approved, not rejected, not a draft
        public bool $publiclyVisible,
        public null|string $createdAt,
        public null|string $addedByPlayerId,
        public null|string $addedByPlayerName,
        public int $editionsCount,
    ) {
    }

    /**
     * @param array{
     *     id: string,
     *     name: string,
     *     slug: null|string,
     *     shortcut: null|string,
     *     description: null|string,
     *     link: null|string,
     *     is_online: bool,
     *     location: null|string,
     *     location_country_code: null|string,
     *     logo: null|string,
     *     organization_id: null|string,
     *     organization_name: null|string,
     *     organization_slug: null|string,
     *     eligibility: null|string,
     *     schedule: null|string,
     *     is_draft: bool,
     *     approved_at: null|string,
     *     approved_by_player_id: null|string,
     *     rejected_at: null|string,
     *     rejection_reason: null|string,
     *     publicly_visible: bool,
     *     created_at: null|string,
     *     added_by_player_id: null|string,
     *     added_by_player_name: null|string,
     *     editions_count: int,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        return new self(
            seriesId: $row['id'],
            name: $row['name'],
            slug: $row['slug'],
            shortcut: $row['shortcut'],
            description: $row['description'],
            link: $row['link'],
            isOnline: $row['is_online'],
            location: $row['location'],
            locationCountryCode: $row['location_country_code'],
            logo: $row['logo'],
            organizationId: $row['organization_id'],
            organizationName: $row['organization_name'],
            organizationSlug: $row['organization_slug'],
            eligibility: $row['eligibility'],
            schedule: $row['schedule'],
            isDraft: $row['is_draft'],
            approvedAt: AdminCompetition::isoDateTime($row['approved_at']),
            approvedByPlayerId: $row['approved_by_player_id'],
            rejectedAt: AdminCompetition::isoDateTime($row['rejected_at']),
            rejectionReason: $row['rejection_reason'],
            publiclyVisible: $row['publicly_visible'],
            createdAt: AdminCompetition::isoDateTime($row['created_at']),
            addedByPlayerId: $row['added_by_player_id'],
            addedByPlayerName: $row['added_by_player_name'],
            editionsCount: $row['editions_count'],
        );
    }

    /**
     * The approval state - drafts aside (`draft` tells those)
     */
    public function status(): string
    {
        if ($this->rejectedAt !== null) {
            return 'rejected';
        }

        return $this->approvedAt !== null ? 'approved' : 'pending';
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'seriesId' => $this->seriesId,
            'name' => $this->name,
            'slug' => $this->slug,
            'shortcut' => $this->shortcut,
            'description' => $this->description,
            'link' => $this->link,
            'isOnline' => $this->isOnline,
            'location' => $this->location,
            'locationCountryCode' => $this->locationCountryCode,
            'logo' => $this->logo,
            'organizationId' => $this->organizationId,
            'organization' => $this->organizationId === null ? null : [
                'organizationId' => $this->organizationId,
                'name' => $this->organizationName,
                'slug' => $this->organizationSlug,
            ],
            'eligibility' => $this->eligibility,
            'schedule' => $this->schedule,
            'status' => $this->status(),
            'draft' => $this->isDraft,
            'approvedAt' => $this->approvedAt,
            'approvedByPlayerId' => $this->approvedByPlayerId,
            'rejectedAt' => $this->rejectedAt,
            'rejectionReason' => $this->rejectionReason,
            'publiclyVisible' => $this->publiclyVisible,
            'createdAt' => $this->createdAt,
            'addedByPlayerId' => $this->addedByPlayerId,
            'addedByPlayerName' => $this->addedByPlayerName,
            'editionsCount' => $this->editionsCount,
        ];
    }
}
