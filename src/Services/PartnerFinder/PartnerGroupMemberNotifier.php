<?php

declare(strict_types=1);

namespace SpeedPuzzling\Web\Services\PartnerFinder;

use DateTimeImmutable;
use Ramsey\Uuid\Uuid;
use SpeedPuzzling\Web\Entity\Notification;
use SpeedPuzzling\Web\Entity\PartnerListing;
use SpeedPuzzling\Web\Entity\Player;
use SpeedPuzzling\Web\Exceptions\PlayerNotFound;
use SpeedPuzzling\Web\Repository\NotificationRepository;
use SpeedPuzzling\Web\Repository\PlayerRepository;
use SpeedPuzzling\Web\Value\NotificationType;

/**
 * Somebody named in a group listing is shown on it publicly, so they are told once - when they are added, never again on
 * an edit that keeps them (docs/features/partner-finder.md "Phase 2 as built"). Two steps, so a handler loads everything
 * before it changes anything: newMembers() first, notify() after the listing is saved.
 */
readonly final class PartnerGroupMemberNotifier
{
    public function __construct(
        private PlayerRepository $playerRepository,
        private NotificationRepository $notificationRepository,
    ) {
    }

    /**
     * @param list<string> $before co-member ids the listing had (none for a new one)
     * @param list<string> $after the checked co-member ids it gets (PartnerListingRules::coMembers())
     *
     * @return list<Player>
     *
     * @throws PlayerNotFound
     */
    public function newMembers(array $before, array $after): array
    {
        $before = array_map(strtolower(...), $before);
        $members = [];

        foreach ($after as $playerId) {
            if (in_array(strtolower($playerId), $before, true) === false) {
                $members[] = $this->playerRepository->get($playerId);
            }
        }

        return $members;
    }

    /**
     * @param list<Player> $members from newMembers()
     */
    public function notify(array $members, PartnerListing $listing, DateTimeImmutable $now): void
    {
        foreach ($members as $member) {
            $this->notificationRepository->save(new Notification(
                Uuid::uuid7(),
                $member,
                NotificationType::PartnerGroupMember,
                $now,
                actorPlayer: $listing->player,
                targetPartnerListing: $listing,
            ));
        }
    }
}
