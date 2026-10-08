<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\MessageHandler;

use Psr\Clock\ClockInterface;
use SpeedPuzzling\Web\Entity\Competition;
use SpeedPuzzling\Web\Entity\Organization;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\OrganizationNotManaged;
use SpeedPuzzling\Web\Exceptions\OrganizationOnEdition;
use SpeedPuzzling\Web\Message\AssignEventToOrganization;
use SpeedPuzzling\Web\Repository\CompetitionRepository;
use SpeedPuzzling\Web\Repository\CompetitionSeriesRepository;
use SpeedPuzzling\Web\Repository\OrganizationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Services\Organizations\OrganizationApprovalPolicy;
use SpeedPuzzling\Web\Value\OrganizationItemKind;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

/**
 * Moves a series or a one-time event into an organization (or out of it). An edition is refused - it is its series'
 * (OrganizationOnEdition); moving into an organization needs its team (or an admin, OrganizationNotManaged); moving out
 * needs only the right to edit the item, which the caller checked. A pending item moved under a trusted organization is
 * approved at once (D2); moving out keeps its approval.
 */
#[AsMessageHandler]
readonly final class AssignEventToOrganizationHandler
{
    public function __construct(
        private CompetitionRepository $competitionRepository,
        private CompetitionSeriesRepository $competitionSeriesRepository,
        private OrganizationRepository $organizationRepository,
        private PlayerRepository $playerRepository,
        private OrganizationApprovalPolicy $organizationApprovalPolicy,
        private ClockInterface $clock,
    ) {
    }

    /**
     * @throws OrganizationOnEdition
     * @throws OrganizationNotManaged
     */
    public function __invoke(AssignEventToOrganization $message): void
    {
        $actor = $this->playerRepository->get($message->actingPlayerId);
        $organization = $message->organizationId !== null ? $this->organizationRepository->get($message->organizationId) : null;

        $item = $message->kind === OrganizationItemKind::Series
            ? $this->competitionSeriesRepository->get($message->itemId)
            : $this->competitionRepository->get($message->itemId);

        if ($organization !== null && $item instanceof Competition && $item->series !== null) {
            throw new OrganizationOnEdition();
        }

        if ($organization !== null) {
            self::assertOnTeam($organization, $actor);
        }

        $item->assignOrganization($organization);
        $this->organizationApprovalPolicy->approveIfUnderTrustedOrganization($item, $actor, $this->clock->now());
    }

    /**
     * @throws OrganizationNotManaged
     */
    private static function assertOnTeam(Organization $organization, Player $actor): void
    {
        if ($actor->isAdmin === false && $organization->isOnTeam($actor) === false) {
            throw new OrganizationNotManaged();
        }
    }
}
