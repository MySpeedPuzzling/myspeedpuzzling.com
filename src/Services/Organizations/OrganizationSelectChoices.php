<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\Organizations;

use SpeedPuzzling\Web\Exceptions\OrganizationNotFound;
use SpeedPuzzling\Web\Query\GetOrganization;
use SpeedPuzzling\Web\Query\GetOrganizations;
use SpeedPuzzling\Web\Results\OrganizationChoice;

/**
 * The options of the "Organization" select of the event and series forms (docs/features/organizations/README.md
 * "Forms"): the organizations the player is on the team of, every one not rejected for admins - plus the item's current
 * organization, so somebody who edits an item under an organization they are not on the team of keeps it by saving (or
 * moves it out by choosing "None").
 */
readonly final class OrganizationSelectChoices
{
    public function __construct(
        private GetOrganizations $getOrganizations,
        private GetOrganization $getOrganization,
    ) {
    }

    /**
     * @return list<OrganizationChoice>
     */
    public function forPlayer(string $playerId, bool $isAdmin, null|string $currentOrganizationId = null): array
    {
        $choices = $isAdmin ? $this->getOrganizations->allChoices() : $this->getOrganizations->choicesForPlayer($playerId);

        if ($currentOrganizationId === null) {
            return $choices;
        }

        foreach ($choices as $choice) {
            if ($choice->id === $currentOrganizationId) {
                return $choices;
            }
        }

        try {
            $current = $this->getOrganization->byId($currentOrganizationId);
        } catch (OrganizationNotFound) {
            return $choices;
        }

        return [
            new OrganizationChoice(
                id: $current->id,
                name: $current->name,
                isDraft: $current->isDraft,
                isApproved: $current->approvedAt !== null,
            ),
            ...$choices,
        ];
    }

    /**
     * The id of the choices that equals the given one (an `?organization=` of a link), else null
     *
     * @param list<OrganizationChoice> $choices
     */
    public static function pick(array $choices, string $organizationId): null|string
    {
        $organizationId = strtolower(trim($organizationId));

        foreach ($choices as $choice) {
            if ($choice->id === $organizationId) {
                return $choice->id;
            }
        }

        return null;
    }
}
