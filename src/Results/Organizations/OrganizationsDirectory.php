<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Results\Organizations;

/**
 * The organizations directory (OrganizationsDirectoryBuilder, docs/features/organizations/README.md "Directory"):
 * the publicly visible organizations, alphabetically.
 */
readonly final class OrganizationsDirectory
{
    /**
     * @param list<OrganizationsDirectoryItem> $items
     */
    public function __construct(
        public array $items,
    ) {
    }

    public function count(): int
    {
        return count($this->items);
    }
}
