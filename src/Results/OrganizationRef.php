<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results;

/**
 * The organization an event, edition or series belongs to - what an "Organized by" link, a crumb and the events page
 * search need (docs/features/organizations/README.md). It rides on the statement each page runs anyway. `isPublic` =
 * IsOrganizationPubliclyVisible: only then do others see the link.
 */
readonly final class OrganizationRef
{
    public function __construct(
        public string $id,
        public string $name,
        public null|string $shortName,
        public string $slug,
        public bool $isPublic,
    ) {
    }

    /**
     * From the `{prefix}id`, `{prefix}name`, `{prefix}short_name`, `{prefix}slug` and `{prefix}public` columns - null when
     * the row has no organization.
     *
     * @param array<string, mixed> $row
     */
    public static function fromRow(array $row, string $prefix = 'organization_'): null|self
    {
        $id = $row[$prefix . 'id'] ?? null;

        if (is_string($id) === false || $id === '') {
            return null;
        }

        $shortName = $row[$prefix . 'short_name'] ?? null;
        $public = $row[$prefix . 'public'] ?? false;

        return new self(
            id: $id,
            name: is_string($row[$prefix . 'name'] ?? null) ? $row[$prefix . 'name'] : '',
            shortName: is_string($shortName) && $shortName !== '' ? $shortName : null,
            slug: is_string($row[$prefix . 'slug'] ?? null) ? $row[$prefix . 'slug'] : '',
            isPublic: $public === true || $public === 't' || $public === '1' || $public === 'true' || $public === 1,
        );
    }
}
