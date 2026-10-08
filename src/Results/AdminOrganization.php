<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * An organization as the internal API shows it to an admin - approved, pending, rejected or a draft, with every field
 * the API can edit (docs/features/internal-api.md "Organizations, series and drafts").
 */
readonly final class AdminOrganization
{
    /**
     * @param list<string> $socialLinks
     */
    public function __construct(
        public string $organizationId,
        public string $name,
        public null|string $shortName,
        public string $slug,
        public null|string $logo,
        public null|string $about,
        public null|string $website,
        public array $socialLinks,
        public null|string $countryCode,
        public null|string $region,
        public null|string $kind,
        public bool $isDraft,
        public null|string $approvedAt,
        public null|string $approvedByPlayerId,
        public null|string $rejectedAt,
        public null|string $rejectionReason,
        // IsOrganizationPubliclyVisible - approved, not rejected, not a draft
        public bool $publiclyVisible,
        public string $createdAt,
        public null|string $addedByPlayerId,
        public null|string $addedByPlayerName,
        public int $seriesCount,
        public int $eventsCount,
    ) {
    }

    /**
     * @param array{
     *     id: string,
     *     name: string,
     *     short_name: null|string,
     *     slug: string,
     *     logo: null|string,
     *     about: null|string,
     *     website: null|string,
     *     social_links: string,
     *     country_code: null|string,
     *     region: null|string,
     *     kind: null|string,
     *     is_draft: bool,
     *     approved_at: null|string,
     *     approved_by_player_id: null|string,
     *     rejected_at: null|string,
     *     rejection_reason: null|string,
     *     publicly_visible: bool,
     *     created_at: string,
     *     added_by_player_id: null|string,
     *     added_by_player_name: null|string,
     *     series_count: int,
     *     events_count: int,
     * } $row
     */
    public static function fromDatabaseRow(array $row): self
    {
        $links = json_decode($row['social_links'], true);

        return new self(
            organizationId: $row['id'],
            name: $row['name'],
            shortName: $row['short_name'],
            slug: $row['slug'],
            logo: $row['logo'],
            about: $row['about'],
            website: $row['website'],
            socialLinks: is_array($links) ? array_values(array_filter($links, is_string(...))) : [],
            countryCode: $row['country_code'],
            region: $row['region'],
            kind: $row['kind'],
            isDraft: $row['is_draft'],
            approvedAt: AdminCompetition::isoDateTime($row['approved_at']),
            approvedByPlayerId: $row['approved_by_player_id'],
            rejectedAt: AdminCompetition::isoDateTime($row['rejected_at']),
            rejectionReason: $row['rejection_reason'],
            publiclyVisible: $row['publicly_visible'],
            createdAt: AdminCompetition::isoDateTime($row['created_at']) ?? $row['created_at'],
            addedByPlayerId: $row['added_by_player_id'],
            addedByPlayerName: $row['added_by_player_name'],
            seriesCount: $row['series_count'],
            eventsCount: $row['events_count'],
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
            'organizationId' => $this->organizationId,
            'name' => $this->name,
            'shortName' => $this->shortName,
            'slug' => $this->slug,
            'logo' => $this->logo,
            'about' => $this->about,
            'website' => $this->website,
            'socialLinks' => $this->socialLinks,
            'countryCode' => $this->countryCode,
            'region' => $this->region,
            'kind' => $this->kind,
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
            'seriesCount' => $this->seriesCount,
            'eventsCount' => $this->eventsCount,
        ];
    }
}
