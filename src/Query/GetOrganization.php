<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Query;

use DateTimeImmutable;
use Doctrine\DBAL\Connection;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Exceptions\OrganizationNotFound;
use SpeedPuzzling\Web\Results\OrganizationDetail;
use SpeedPuzzling\Web\Value\CountryCode;
use SpeedPuzzling\Web\Value\OrganizationKind;
use SpeedPuzzling\Web\Value\SocialLink;
use SpeedPuzzling\Web\Value\SocialLinkPlatform;
use SpeedPuzzling\Web\Value\SocialLinks;

/**
 * One organization in any state, in one statement (docs/features/organizations/README.md) - the page decides what a
 * viewer may see (a draft is 404 for everyone but its team, DraftNotVisible).
 */
readonly final class GetOrganization
{
    public const string COLUMNS = 'o.id, o.name, o.short_name, o.slug, o.logo, o.about, o.website, o.social_links, o.country_code, o.region, o.kind, o.is_draft, o.approved_at, o.rejected_at, o.rejection_reason, o.added_by_player_id, o.created_at';

    public function __construct(
        private Connection $database,
    ) {
    }

    /**
     * @throws OrganizationNotFound
     */
    public function bySlug(string $slug): OrganizationDetail
    {
        $columns = self::COLUMNS;

        $row = $this->database
            ->executeQuery("SELECT {$columns} FROM organization o WHERE o.slug = :slug", ['slug' => $slug])
            ->fetchAssociative();

        if ($row === false) {
            throw new OrganizationNotFound();
        }

        return self::hydrate($row);
    }

    /**
     * @throws OrganizationNotFound
     */
    public function byId(string $organizationId): OrganizationDetail
    {
        if (Uuid::isValid($organizationId) === false) {
            throw new OrganizationNotFound();
        }

        $columns = self::COLUMNS;

        $row = $this->database
            ->executeQuery("SELECT {$columns} FROM organization o WHERE o.id = :id", ['id' => $organizationId])
            ->fetchAssociative();

        if ($row === false) {
            throw new OrganizationNotFound();
        }

        return self::hydrate($row);
    }

    /**
     * From the COLUMNS (+ an optional `added_by_player_name`)
     *
     * @param array<string, mixed> $row
     */
    public static function hydrate(array $row): OrganizationDetail
    {
        $kind = self::string($row['kind'] ?? null);

        return new OrganizationDetail(
            id: (string) self::string($row['id']),
            name: (string) self::string($row['name']),
            shortName: self::string($row['short_name']),
            slug: (string) self::string($row['slug']),
            logo: self::string($row['logo']),
            about: self::string($row['about']),
            website: self::string($row['website']),
            socialLinks: self::socialLinks($row['social_links'] ?? null),
            countryCode: CountryCode::fromCode(self::string($row['country_code'])),
            region: self::string($row['region']),
            kind: $kind !== null ? OrganizationKind::tryFrom($kind) : null,
            isDraft: (bool) $row['is_draft'],
            approvedAt: self::instant($row['approved_at']),
            rejectedAt: self::instant($row['rejected_at']),
            rejectionReason: self::string($row['rejection_reason']),
            addedByPlayerId: self::string($row['added_by_player_id']),
            addedByPlayerName: self::string($row['added_by_player_name'] ?? null),
            createdAt: self::instant($row['created_at']) ?? new DateTimeImmutable('@0'),
        );
    }

    /**
     * The stored JSON list, read leniently: a value that is not an http(s) address is left out (SocialLinks guards
     * every write, so this never drops anything written by the app)
     *
     * @return list<SocialLink>
     */
    private static function socialLinks(mixed $json): array
    {
        $urls = is_string($json) ? json_decode($json, true) : null;

        if (is_array($urls) === false) {
            return [];
        }

        $links = [];

        foreach ($urls as $url) {
            if (is_string($url) && SocialLinks::isWebAddress($url)) {
                $links[] = new SocialLink($url, SocialLinkPlatform::fromUrl($url), SocialLinkPlatform::hostOf($url));
            }
        }

        return array_slice($links, 0, SocialLinks::MAX);
    }

    private static function string(mixed $value): null|string
    {
        return is_string($value) && $value !== '' ? $value : null;
    }

    private static function instant(mixed $value): null|DateTimeImmutable
    {
        return is_string($value) && $value !== '' ? new DateTimeImmutable($value) : null;
    }
}
